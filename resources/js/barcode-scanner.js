/**
 * Lectura de códigos de barras con la cámara, con html5-qrcode.
 *
 * Se eligió esta librería sobre ZXing porque gestiona por sí sola lo difícil en el móvil: pedir
 * permiso, elegir la cámara trasera, enfocar y decodificar códigos 1D (EAN/UPC/Code128) —que es lo
 * que trae un producto— de forma fiable. El lector general de ZXing fallaba justo ahí.
 *
 * No se importa desde app.js: se carga con import() dinámico la primera vez que se abre la cámara,
 * así su peso no lastra a quien escanea con lector de pistola.
 */

import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode';

// Formatos de comercio: EAN/UPC en producto de fábrica, Code128/39 en etiquetas propias, y QR por
// si acaso. Acotarlos hace la lectura más rápida y con menos falsos positivos.
const FORMATOS = [
    Html5QrcodeSupportedFormats.EAN_13,
    Html5QrcodeSupportedFormats.EAN_8,
    Html5QrcodeSupportedFormats.UPC_A,
    Html5QrcodeSupportedFormats.UPC_E,
    Html5QrcodeSupportedFormats.CODE_128,
    Html5QrcodeSupportedFormats.CODE_39,
    Html5QrcodeSupportedFormats.QR_CODE,
];

/**
 * Arranca la cámara dentro de `contenedor` y avisa con cada código leído.
 *
 * @param {HTMLElement} contenedor  Div donde la librería pinta la cámara (debe tener tamaño).
 * @param {(codigo: string) => void} alLeer
 * @returns {Promise<{ detener: () => Promise<void> }>}
 */
export async function iniciar(contenedor, alLeer) {
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        throw new Error(
            'La cámara necesita una conexión segura (HTTPS). Desde el móvil por IP de red local no ' +
            'está disponible: usa el lector de pistola o accede por HTTPS.'
        );
    }

    // html5-qrcode identifica el contenedor por id.
    if (!contenedor.id) {
        contenedor.id = 'scan-' + Math.random().toString(36).slice(2, 10);
    }

    const lector = new Html5Qrcode(contenedor.id, { formatsToSupport: FORMATOS, verbose: false });
    let yaLeido = false;

    // La caja de escaneo: ancha y baja, la forma de un código de barras 1D.
    const qrbox = (anchoVista, altoVista) => {
        const lado = Math.min(anchoVista, altoVista);
        return { width: Math.floor(lado * 0.85), height: Math.floor(lado * 0.5) };
    };

    await lector.start(
        { facingMode: 'environment' }, // cámara trasera
        { fps: 12, qrbox, aspectRatio: 1.334 },
        (texto) => {
            // Una lectura basta. Se marca antes de avisar para no disparar dos veces.
            if (!yaLeido) {
                yaLeido = true;
                alLeer(texto);
            }
        },
        () => {
            // Se llama en cada fotograma sin código: no es un error, se ignora.
        },
    );

    return {
        detener: async () => {
            try { await lector.stop(); } catch (e) { /* ya estaba parada */ }
            try { lector.clear(); } catch (e) { /* nada que limpiar */ }
        },
    };
}
