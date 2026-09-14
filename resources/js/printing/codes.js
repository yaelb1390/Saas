/**
 * QR y código de barras: se dibujan EN EL NAVEGADOR, nunca en el servidor.
 *
 * Por qué: es lo que deja que la vista previa del editor de plantillas se actualice al instante con
 * cada tecla —sin ir y volver al servidor por una imagen— y evita sumar una librería de generación de
 * QR en PHP solo para un dato que el navegador ya sabe pintar. El QR que sale por Bluetooth es otro
 * camino —lo genera la propia impresora térmica, con el comando ESC/POS que arma el servidor—.
 *
 * Las librerías se cargan BAJO DEMANDA (`import()`): la inmensa mayoría de páginas de BMIA no
 * imprimen nada con QR, y cargar esto en el bundle principal sería peso que casi nadie usa.
 */

let qrCode = null;
let jsBarcode = null;

async function cargarQr() {
    if (!qrCode) {
        qrCode = (await import('qrcode')).default;
    }

    return qrCode;
}

async function cargarBarcode() {
    if (!jsBarcode) {
        jsBarcode = (await import('jsbarcode')).default;
    }

    return jsBarcode;
}

/**
 * Busca todos los `<canvas data-qr-content>` y `<svg data-barcode-content>` DENTRO de un contenedor
 * (la vista previa, o el documento que se está por imprimir) y los dibuja. Los elementos ya salen del
 * servidor con el atributo puesto —ver `panel.printing.partials.document`—; aquí solo se rellenan.
 */
export async function dibujarCodigos(contenedor) {
    const qrs = contenedor.querySelectorAll('canvas[data-qr-content]');
    const barras = contenedor.querySelectorAll('svg[data-barcode-content]');

    if (qrs.length > 0) {
        const QRCode = await cargarQr();
        for (const el of qrs) {
            const texto = el.dataset.qrContent;
            if (texto) {
                try {
                    await QRCode.toCanvas(el, texto, { width: Number(el.width) || 90, margin: 0 });
                } catch { /* un contenido no válido para QR no debe tumbar el resto de la vista previa */ }
            }
        }
    }

    if (barras.length > 0) {
        const JsBarcode = await cargarBarcode();
        for (const el of barras) {
            const texto = el.dataset.barcodeContent;
            if (texto) {
                try {
                    JsBarcode(el, texto, { format: 'CODE39', displayValue: true, height: 40, fontSize: 11, margin: 4 });
                } catch { /* CODE39 no acepta todo: minúsculas, algunos símbolos. Se deja el hueco vacío. */ }
            }
        }
    }
}
