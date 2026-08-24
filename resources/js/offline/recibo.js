/**
 * El recibo de una venta cobrada sin internet.
 *
 * Hace falta porque el recibo normal lo dibuja el servidor (`panel.sales.receipt`) y sin línea no
 * hay servidor. Sin esto el cliente pagaría y se iría con las manos vacías, que en un mostrador no
 * es un detalle: es la prueba de lo que compró y de lo que dio.
 *
 * NO ES UNA FACTURA. No lleva NCF y lo dice en grande, porque un papel que parece fiscal y no lo es
 * causa más problemas que no dar ninguno: el cliente lo presenta para gastos y descubre tarde que no
 * vale. El comprobante fiscal se emite cuando vuelva la conexión.
 *
 * Se imprime desde la propia página —en un contenedor oculto con `@media print`— y no abriendo una
 * ventana nueva: los bloqueadores de ventanas emergentes tumbarían la impresión justo cuando no hay
 * red para diagnosticar nada.
 */

const CONTENEDOR = 'bmos-recibo-offline';

function escapar(texto) {
    const d = document.createElement('div');
    d.textContent = texto ?? '';

    return d.innerHTML;
}

function dinero(valor) {
    return Number(valor ?? 0).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function contenedor() {
    let nodo = document.getElementById(CONTENEDOR);

    if (!nodo) {
        nodo = document.createElement('div');
        nodo.id = CONTENEDOR;
        document.body.appendChild(nodo);
    }

    return nodo;
}

/**
 * @param {object} venta   lo mismo que se guardó en la cola
 * @param {object} negocio { nombre, rnc, direccion, telefono }
 * @param {Array}  detalle líneas ya legibles: { nombre, cantidad, precio, importe }
 */
export function imprimir(venta, negocio, detalle) {
    /*
     * El «Ref.» impreso son los primeros ocho caracteres del UUID, y es lo único que ata este papel
     * con la fila que hay en el terminal. Si la venta se queda apartada al subirla, es por ahí por
     * donde se puede rastrear la reclamación de un cliente que llega con su recibo en la mano.
     */
    const total = detalle.reduce((suma, l) => suma + Number(l.importe ?? 0), 0);

    contenedor().innerHTML = `
        <div class="bmos-recibo-hoja">
            <h1>${escapar(negocio.nombre)}</h1>
            ${negocio.rnc ? `<p>RNC ${escapar(negocio.rnc)}</p>` : ''}
            ${negocio.direccion ? `<p>${escapar(negocio.direccion)}</p>` : ''}
            ${negocio.telefono ? `<p>${escapar(negocio.telefono)}</p>` : ''}

            <p class="bmos-recibo-aviso">
                RECIBO PROVISIONAL<br>
                Sin comprobante fiscal (NCF).<br>
                Cobrado sin conexión: pendiente de registrar.
            </p>

            <p>${new Date(venta.cobrado_en).toLocaleString('es-DO')}</p>

            <table>
                ${detalle.map((l) => `
                    <tr>
                        <td>${escapar(l.nombre)}</td>
                        <td class="n">${escapar(String(l.cantidad))} × ${dinero(l.precio)}</td>
                        <td class="n">${dinero(l.importe)}</td>
                    </tr>
                `).join('')}
            </table>

            <table class="bmos-recibo-totales">
                <tr><td>Total</td><td class="n">${dinero(total)}</td></tr>
                <tr><td>Recibido</td><td class="n">${dinero(venta.paid)}</td></tr>
                <tr><td>Cambio</td><td class="n">${dinero(Number(venta.paid ?? 0) - total)}</td></tr>
            </table>

            <p class="bmos-recibo-uuid">Ref. ${escapar(venta.uuid.slice(0, 8))}</p>
        </div>
    `;

    window.print();
}
