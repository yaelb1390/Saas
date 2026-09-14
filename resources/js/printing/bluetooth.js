/**
 * Web Bluetooth: el único camino que tiene una página para hablarle a una impresora térmica.
 *
 * TRES LÍMITES QUE ESTE ARCHIVO NO PUEDE SALTARSE, PORQUE SON DEL NAVEGADOR, NO NUESTROS:
 *
 *   1. Solo existe en Chrome y Edge —de escritorio y Android—. Nunca en Safari/iPhone: Apple no lo
 *      implementa. `isSupported()` es lo primero que hay que preguntar, y la interfaz debe explicar
 *      esto, no fallar en silencio.
 *   2. NO HAY «BUSCAR CERCANAS» AUTOMÁTICO. El navegador exige que la persona vea y elija el
 *      dispositivo en SU PROPIO selector nativo (`requestDevice`). Este archivo no puede listar nada
 *      por su cuenta; solo puede pedirle al navegador que abra ese selector.
 *   3. Exige HTTPS (o `localhost`/`127.0.0.1`, que cuentan como seguros). En producción, sin HTTPS
 *      esto no aparece.
 *
 * Los UUID de servicio/característica que se prueban son los que usa la inmensa mayoría de térmicas
 * chinas genéricas (las más comunes en el Caribe) y las que siguen el perfil "Generic Access" con
 * una característica de escritura sin respuesta. Una impresora rara puede necesitar otro UUID: por
 * eso `Printer.bt_service_uuid`/`bt_characteristic_uuid` quedan editables a mano en el registro.
 */

// UUID de servicio "candidatos", en el orden en que se prueban. El primero es el que usan casi todas
// las térmicas ESC/POS genéricas por Bluetooth Low Energy (BLE).
const SERVICIOS_CANDIDATOS = [
    '000018f0-0000-1000-8000-00805f9b34fb', // El más común en impresoras térmicas BLE genéricas.
    '0000ff00-0000-1000-8000-00805f9b34fb',
];

const CARACTERISTICAS_CANDIDATAS = [
    '00002af1-0000-1000-8000-00805f9b34fb',
    '0000ff02-0000-1000-8000-00805f9b34fb',
];

const SERVICIO_BATERIA = 0x180f;
const CARACTERISTICA_NIVEL_BATERIA = 0x2a19;

export function isSupported() {
    return typeof navigator !== 'undefined' && 'bluetooth' in navigator;
}

/**
 * Por qué no está disponible, en una frase que se le puede enseñar a quien está frente a la caja.
 * `null` si sí está disponible.
 *
 * ASÍNCRONA A PROPÓSITO: `'bluetooth' in navigator` solo dice que el NAVEGADOR conoce la API, no que
 * de verdad se pueda usar. Brave es el caso que lo demuestra —es Chromium, así que la API está—,
 * pero Brave la BLOQUEA por defecto como protección de privacidad, y `getAvailability()` es la única
 * forma de verlo de antemano: sin esto, el botón se enseña como si fuera a funcionar y falla en
 * silencio al primer clic, que es justo el síntoma difícil de diagnosticar.
 */
export async function motivoNoDisponible() {
    if (typeof navigator === 'undefined') return 'No se pudo comprobar el navegador.';
    if (!('bluetooth' in navigator)) {
        return 'Este navegador no admite Bluetooth para impresoras. Usa Chrome o Edge en una computadora o en Android — no está disponible en iPhone.';
    }
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        return 'Bluetooth exige una conexión segura (https). Esta página no lo es.';
    }

    try {
        const disponible = await navigator.bluetooth.getAvailability();
        if (!disponible) {
            return 'El navegador no encuentra un adaptador Bluetooth activo. Si usas Brave, esta función viene apagada por privacidad: actívala en brave://settings/privacy → «Usar Bluetooth», o revisa que el Bluetooth del equipo esté encendido.';
        }
    } catch {
        // getAvailability() no está en todos los navegadores con soporte de Web Bluetooth; su
        // ausencia no significa que Bluetooth no sirva, solo que no se puede adelantar el aviso.
    }

    return null;
}

/**
 * Abre EL SELECTOR DEL NAVEGADOR para que la persona elija su impresora. No busca nada por su
 * cuenta —eso no lo permite el navegador—, solo ofrece el diálogo nativo.
 *
 * `cancelado: true` cuando la persona cerró el selector sin elegir nada —no es un error, se calla—.
 * Cualquier otro fallo (adaptador bloqueado, sin Bluetooth en el equipo…) se relanza con un mensaje
 * claro: los dos casos comparten el mismo `DOMException.name` ("NotFoundError") y solo se
 * distinguen por el TEXTO del mensaje, así que tratarlos igual —como hacía antes esta función—
 * esconde justo el fallo que hay que enseñar (el caso de Brave, con Bluetooth apagado por
 * privacidad, es exactamente este).
 *
 * @returns {Promise<{device: BluetoothDevice, name: string, deviceId: string}|{cancelado: true}>}
 */
export async function elegirDispositivo() {
    let device;

    try {
        device = await navigator.bluetooth.requestDevice({
            // acceptAllDevices dentro de optionalServices: se acepta cualquier dispositivo Bluetooth
            // (no solo impresoras identificadas por nombre), porque no hay forma de filtrar por "es
            // una impresora" antes de conectar y preguntarle sus servicios.
            acceptAllDevices: true,
            optionalServices: [...SERVICIOS_CANDIDATOS, SERVICIO_BATERIA],
        });
    } catch (e) {
        if (e?.name === 'NotFoundError' && /cancel/i.test(e.message || '')) {
            return { cancelado: true };
        }

        if (e?.name === 'NotFoundError') {
            throw new Error('No se encontró un adaptador Bluetooth activo. Si usas Brave, actívalo en brave://settings/privacy → «Usar Bluetooth»; si no, revisa que el Bluetooth del equipo esté encendido.');
        }

        throw e;
    }

    return { device, name: device.name || 'Dispositivo sin nombre', deviceId: device.id };
}

/**
 * Conecta de verdad: abre el GATT y busca un servicio/característica escribible, probando primero
 * los que trae la impresora registrada (si los tiene) y si no, los candidatos conocidos.
 *
 * @returns {Promise<{server: BluetoothRemoteGATTServer, characteristic: BluetoothRemoteGATTCharacteristic, batteryLevel: ?number}>}
 */
export async function conectar(device, { serviceUuid, characteristicUuid } = {}) {
    const server = await device.gatt.connect();

    const serviciosAProbar = serviceUuid ? [serviceUuid, ...SERVICIOS_CANDIDATOS] : SERVICIOS_CANDIDATOS;
    let characteristic = null;

    for (const uuid of serviciosAProbar) {
        try {
            const service = await server.getPrimaryService(uuid);
            const caracteristicasAProbar = characteristicUuid ? [characteristicUuid, ...CARACTERISTICAS_CANDIDATAS] : CARACTERISTICAS_CANDIDATAS;

            for (const cuuid of caracteristicasAProbar) {
                try {
                    characteristic = await service.getCharacteristic(cuuid);
                    break;
                } catch { /* esta característica no está en este servicio; se prueba la siguiente */ }
            }

            if (characteristic) break;
        } catch { /* este servicio no lo tiene la impresora; se prueba el siguiente candidato */ }
    }

    if (!characteristic) {
        throw new Error('Se conectó, pero no se encontró un canal de escritura conocido en esta impresora. Puede que necesite un UUID de servicio distinto (revisa el manual del fabricante).');
    }

    return { server, characteristic, batteryLevel: await leerBateria(server) };
}

/**
 * Reconecta SIN mostrar el selector, a un dispositivo que YA se emparejó antes (en cualquier
 * pestaña, no solo esta sesión). Hace falta porque una conexión GATT no sobrevive a cambiar de
 * página: el botón «Imprimir» de un recibo de venta —una página distinta al Centro de Impresión—
 * no hereda la conexión que se abrió allí, así que tiene que reconectar por su cuenta.
 *
 * Se apoya en `navigator.bluetooth.getDevices()`, que expone los dispositivos a los que el
 * NAVEGADOR (no esta pestaña) ya dio permiso antes. Sin ese permiso previo —la impresora nunca se
 * emparejó desde este navegador— no hay forma de reconectar sin el selector: se devuelve null y
 * quien llama decide si cae al diálogo de imprimir o pide ir a emparejarla primero.
 */
export async function reconectarGuardado(deviceId, opciones = {}) {
    if (!navigator.bluetooth?.getDevices || !deviceId) return null;

    const dispositivos = await navigator.bluetooth.getDevices();
    const device = dispositivos.find((d) => d.id === deviceId);

    if (!device) return null;

    return { device, ...(await conectar(device, opciones)) };
}

async function leerBateria(server) {
    try {
        const service = await server.getPrimaryService(SERVICIO_BATERIA);
        const characteristic = await service.getCharacteristic(CARACTERISTICA_NIVEL_BATERIA);
        const value = await characteristic.readValue();

        return value.getUint8(0);
    } catch {
        // La inmensa mayoría de térmicas NO exponen el servicio de batería: no es un error, es lo
        // normal. Se devuelve null y la interfaz simplemente no enseña el indicador.
        return null;
    }
}

/**
 * Transmite los comandos ESC/POS (que llegan en base64 desde el servidor) en trozos pequeños: el
 * MTU típico de una conexión BLE ronda los 20 bytes, y escribir de golpe un ticket entero se corta a
 * la mitad en la mayoría de impresoras.
 */
export async function imprimirEscPos(characteristic, base64, { tamanoTrozo = 20 } = {}) {
    const binario = atob(base64);
    const bytes = new Uint8Array(binario.length);
    for (let i = 0; i < binario.length; i++) bytes[i] = binario.charCodeAt(i);

    for (let i = 0; i < bytes.length; i += tamanoTrozo) {
        const trozo = bytes.slice(i, i + tamanoTrozo);
        // writeValueWithoutResponse es más rápido y lo que esperan estas impresoras; se cae a
        // writeValue si el navegador o la característica no lo admiten.
        if (characteristic.writeValueWithoutResponse) {
            await characteristic.writeValueWithoutResponse(trozo);
        } else {
            await characteristic.writeValue(trozo);
        }
    }
}

export function desconectar(device) {
    if (device?.gatt?.connected) {
        device.gatt.disconnect();
    }
}
