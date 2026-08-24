/**
 * El almacén local del terminal: catálogo e IndexedDB.
 *
 * IndexedDB y no localStorage, por dos motivos que aquí importan de verdad:
 *
 *  - localStorage tiene unos 5 MB y guarda solo texto; un catálogo de mil productos con sus
 *    opciones se acerca a ese techo, y cuando se pasa lanza una excepción EN MEDIO DEL COBRO.
 *  - localStorage es síncrono: escribir la venta congelaría la pantalla justo cuando el cajero
 *    está dándole al botón.
 *
 * Se escribe a mano en vez de traer una librería (idb, dexie) porque lo que hace falta son cuatro
 * operaciones y ninguna de ellas es sutil. Una dependencia más en la ruta crítica del cobro es una
 * cosa más que puede no cargar el día que falle la red.
 */

const BASE = 'bmos-offline';
const VERSION = 1;

/** El catálogo, con su fecha. Una sola fila: siempre la última copia buena. */
export const CATALOGO = 'catalogo';

/** Las ventas cobradas y todavía no subidas. De aquí NO se borra nada sin confirmación del servidor. */
export const COLA = 'cola';

/**
 * Las ventas que el servidor rechazó y que no van a entrar reintentando.
 *
 * Se apartan en vez de borrarse. Son dinero cobrado: si el sistema no puede registrarlas, al menos
 * que quede constancia de que existieron y de por qué se quedaron fuera.
 */
export const APARTADAS = 'apartadas';

let promesaBase = null;

function abrir() {
    if (promesaBase) return promesaBase;

    promesaBase = new Promise((resolver, rechazar) => {
        const peticion = indexedDB.open(BASE, VERSION);

        peticion.onupgradeneeded = () => {
            const db = peticion.result;
            if (!db.objectStoreNames.contains(CATALOGO)) db.createObjectStore(CATALOGO);
            if (!db.objectStoreNames.contains(COLA)) db.createObjectStore(COLA, { keyPath: 'uuid' });
            if (!db.objectStoreNames.contains(APARTADAS)) db.createObjectStore(APARTADAS, { keyPath: 'uuid' });
        };

        peticion.onsuccess = () => resolver(peticion.result);
        peticion.onerror = () => rechazar(peticion.error);
        // Navegación privada en algunos navegadores: IndexedDB existe pero se bloquea al abrir.
        peticion.onblocked = () => rechazar(new Error('IndexedDB bloqueado'));
    });

    return promesaBase;
}

function transaccion(almacen, modo, trabajo) {
    return abrir().then((db) => new Promise((resolver, rechazar) => {
        const tx = db.transaction(almacen, modo);
        const peticion = trabajo(tx.objectStore(almacen));

        // Se espera a que la TRANSACCIÓN termine, no solo la petición: en IndexedDB una escritura
        // puede reportar éxito y perderse después si la transacción aborta al confirmar.
        tx.oncomplete = () => resolver(peticion ? peticion.result : undefined);
        tx.onerror = () => rechazar(tx.error);
        tx.onabort = () => rechazar(tx.error);
    }));
}

export function guardar(almacen, valor, clave) {
    return transaccion(almacen, 'readwrite', (store) => store.put(valor, clave));
}

export function leer(almacen, clave) {
    return transaccion(almacen, 'readonly', (store) => store.get(clave));
}

export function todo(almacen) {
    return transaccion(almacen, 'readonly', (store) => store.getAll());
}

export function borrar(almacen, clave) {
    return transaccion(almacen, 'readwrite', (store) => store.delete(clave));
}

export function contar(almacen) {
    return transaccion(almacen, 'readonly', (store) => store.count());
}

/**
 * ¿Se puede guardar algo aquí?
 *
 * Si la respuesta es no —navegación privada, almacenamiento deshabilitado, un iOS antiguo— el
 * terminal NO debe ofrecer cobrar sin conexión: prometer que la venta se guarda y perderla es
 * peor que decir de entrada que hoy no se puede.
 */
export async function disponible() {
    if (!('indexedDB' in window)) return false;

    try {
        await abrir();
        return true;
    } catch {
        return false;
    }
}
