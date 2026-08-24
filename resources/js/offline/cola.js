/**
 * La cola de ventas cobradas sin internet, y su subida.
 *
 * LA REGLA QUE MANDA SOBRE TODAS LAS DEMÁS: de la cola no se borra nada que el servidor no haya
 * confirmado. Ni al recargar, ni si caduca la sesión, ni si el envío falla, ni si el servidor
 * contesta algo raro. Cada elemento de esa cola es dinero que un cliente ya pagó; perderlo no deja
 * rastro en ninguna parte y nadie se entera hasta que no cuadra la caja.
 *
 * Un duplicado, en cambio, se ve y se corrige. Por eso ante la duda se reintenta: el UUID que lleva
 * cada venta hace que reintentar sea seguro (ver OfflineSaleSyncService).
 */

import { APARTADAS, COLA, borrar, contar, guardar, todo } from './almacen';

/** Tope por petición. Coincide con el que valida el servidor; pasarse sería un 422 seguro. */
const POR_LOTE = 50;

/** Cada cuánto se reintenta mientras haya ventas esperando. */
const REINTENTO_MS = 30_000;

const oyentes = new Set();

let estado = {
    conexion: navigator.onLine ? 'en-linea' : 'sin-conexion',
    pendientes: 0,
    apartadas: 0,
    subiendo: false,
    // Cuando la sesión ha caducado: la cola se queda quieta y hay que volver a entrar.
    pideLogin: false,
};

let subiendoAhora = false;
let temporizador = null;

function avisar() {
    oyentes.forEach((oyente) => oyente({ ...estado }));
}

export function alCambiar(oyente) {
    oyentes.add(oyente);
    oyente({ ...estado });

    return () => oyentes.delete(oyente);
}

async function recontar() {
    estado.pendientes = await contar(COLA).catch(() => estado.pendientes);
    estado.apartadas = await contar(APARTADAS).catch(() => estado.apartadas);
    avisar();
}

/**
 * Guarda una venta ya cobrada.
 *
 * Devuelve la venta TAL COMO QUEDÓ GUARDADA, no solo su uuid, y el recibo se imprime con eso: son
 * el mismo objeto, así que el papel que se lleva el cliente y la fila que espera en el terminal no
 * pueden decir cosas distintas. Devolviendo solo el uuid, quien imprimía se quedaba con el objeto de
 * antes —sin la hora— y el recibo salía con una fecha inválida.
 */
export async function encolar(venta) {
    const guardada = {
        ...venta,
        uuid: venta.uuid ?? crypto.randomUUID(),
        cobrado_en: venta.cobrado_en ?? new Date().toISOString(),
    };

    await guardar(COLA, guardada);
    await recontar();

    // Se intenta subir de inmediato: si la línea volvió hace un minuto, esta venta no tiene por qué
    // esperar al siguiente ciclo.
    sincronizar();

    return guardada;
}

export function pendientes() {
    return todo(COLA);
}

export function apartadas() {
    return todo(APARTADAS);
}

/**
 * Sube lo que haya, si se puede.
 *
 * Silenciosa a propósito: se la llama sola cada poco y al volver la conexión, y una que falla no es
 * noticia —lo normal es que la línea siga caída—. Lo que sí se refleja siempre es el ESTADO, que es
 * lo que mira el cajero.
 */
export async function sincronizar() {
    if (subiendoAhora || !navigator.onLine) return;

    const cola = await todo(COLA).catch(() => []);
    if (cola.length === 0) return;

    subiendoAhora = true;
    estado.subiendo = true;
    avisar();

    try {
        /*
         * Un token fresco antes de nada.
         *
         * La pantalla puede llevar horas abierta desde una copia guardada, y su token CSRF sería
         * rechazado con un 419. Esta llamada además sirve de comprobación de que la sesión sigue
         * viva: si contesta un redirect al login, se para aquí SIN TOCAR LA COLA.
         */
        const sesion = await fetch('/panel/pos/sesion', {
            headers: { Accept: 'application/json' },
            redirect: 'manual',
        });

        if (!sesion.ok) {
            estado.pideLogin = true;
            return;
        }

        const { token } = await sesion.json();
        estado.pideLogin = false;
        // La petición llegó: hay salida de verdad, diga lo que diga la bandera del navegador.
        estado.conexion = 'en-linea';

        for (let i = 0; i < cola.length; i += POR_LOTE) {
            await subirLote(cola.slice(i, i + POR_LOTE), token);
        }
    } catch {
        /*
         * Se cayó la línea a mitad. La cola sigue intacta y se reintenta en el siguiente ciclo.
         *
         * Y se corrige el estado: el navegador puede estar diciendo «en línea» con el wifi puesto y
         * el router sin salida, y entonces el cajero vería «Por enviar 3 ventas» sin ninguna
         * explicación de por qué no se van.
         */
        estado.conexion = 'sin-conexion';
    } finally {
        subiendoAhora = false;
        estado.subiendo = false;
        await recontar();
    }
}

async function subirLote(lote, token) {
    const res = await fetch('/panel/pos/sincronizar', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({ ventas: lote }),
    });

    /*
     * Cualquier respuesta que no sea un 200 con resultados deja la cola COMO ESTÁ.
     *
     * Un 500 puede ser un fallo pasajero del servidor y mañana entrar sin problema; un 422 significa
     * que algo del lote no cumple, y borrarlo por eso sería tirar la venta a la basura por un
     * detalle de formato. En los dos casos: no tocar nada y que lo vea una persona.
     */
    if (!res.ok) return;

    const { resultados } = await res.json();
    if (!Array.isArray(resultados)) return;

    for (const resultado of resultados) {
        if (resultado.estado === 'registrada' || resultado.estado === 'ya_estaba') {
            // Confirmada por el servidor: ahora sí se puede soltar.
            await borrar(COLA, resultado.uuid);
            continue;
        }

        if (resultado.estado === 'rechazada') {
            /*
             * No va a entrar reintentando —el producto ya no existe, o la venta trae algo imposible—
             * así que se aparta para que deje de bloquear la cola, pero NO se borra: sigue siendo
             * una venta cobrada y alguien tiene que decidir qué hacer con ella.
             */
            const venta = lote.find((v) => v.uuid === resultado.uuid);
            if (venta) await guardar(APARTADAS, { ...venta, motivo: resultado.motivo ?? 'sin motivo' });
            await borrar(COLA, resultado.uuid);
        }
    }
}

/** Arranca los reintentos y la vigilancia de la conexión. Se llama una vez por pantalla del POS. */
export function vigilar() {
    const marcar = (conexion) => {
        estado.conexion = conexion;
        avisar();
        if (conexion === 'en-linea') sincronizar();
    };

    window.addEventListener('online', () => marcar('en-linea'));
    window.addEventListener('offline', () => marcar('sin-conexion'));

    // `navigator.onLine` miente: dice «en línea» con el wifi puesto aunque el router no tenga
    // salida. Por eso además se reintenta cada poco mientras quede algo por subir, y es el propio
    // intento el que descubre si de verdad hay salida.
    temporizador ??= setInterval(sincronizar, REINTENTO_MS);

    recontar();
    sincronizar();
}
