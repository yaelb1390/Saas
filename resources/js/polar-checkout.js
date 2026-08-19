/**
 * Cobro de la suscripción sin sacar al cliente de su panel.
 *
 * Abre el pago en una ventana sobre la propia pantalla en vez de mandarlo a polar.sh. Es el único
 * momento en que el cliente paga, y hasta ahora era justo donde se le enviaba a un dominio que no
 * reconoce.
 *
 * TRES COSAS QUE PARECEN DE MÁS Y NO LO SON:
 *
 * 1. El cobro se pide al SERVIDOR, no se construye aquí. Es lo que hace que el aviso de pago sepa a
 *    qué empresa activar; un enlace fabricado en el navegador no llevaría ese dato y el cliente
 *    pagaría sin recibir nada.
 *
 * 2. Si algo va mal, se envía el formulario a la antigua. Sigue habiendo un <form> de verdad debajo,
 *    y llevar al cliente a la pasarela es peor que quedarse en casa pero infinitamente mejor que un
 *    botón que no hace nada.
 *
 * 3. HAY UN SEGUNDO CAMINO A PROPÓSITO: pagar en la página de Polar.
 *
 *    Apple Pay y Google Pay NO se pueden encender desde aquí. En la ventana embebida vienen
 *    apagados de fábrica —«wallet payment methods are not enabled when you embed our checkout form
 *    into your website»— y hay que pedirle a Polar que autorice el dominio, uno por uno y por
 *    correo. En su página salen solos, sin pedir nada, según el dispositivo del cliente.
 *
 *    Así que quien quiera pagar con el móvil de un toque tiene por dónde, hoy, sin esperar a nadie;
 *    y quien no, no nota ningún cambio. Cuando Polar autorice el dominio, este camino sobra.
 *
 * 4. Terminar de pagar NO activa el plan. Este archivo corre en el navegador del cliente y cualquiera
 *    puede disparar sus eventos desde la consola. Cuando el pago se confirma, lo único que se hace es
 *    PREGUNTAR al servidor si ya está activo, en bucle, hasta que el aviso de Polar llegue.
 */

/** Si el pago no ha aparecido en pantalla en este plazo, es que el dominio no está autorizado. */
const ESPERA_DE_CARGA_MS = 8000;

/** Cuánto se le da al aviso de Polar para llegar antes de rendirse y pedir una recarga. */
const SONDEO_MAXIMO_MS = 90000;

const SONDEO_CADA_MS = 2500;

/**
 * @param {HTMLFormElement} form  El formulario de pago, que sigue siendo el camino de reserva.
 * @param {object} avisos         Callbacks para que la vista cuente lo que pasa.
 */
export async function abrirCobro(form, avisos = {}) {
    const { alEstado = () => {}, alFallo = () => {} } = avisos;

    const url = await pedirCobro(form, avisos);

    if (!url) return;

    let checkout;
    let cargo = false;

    try {
        const { PolarEmbedCheckout } = await import('@polar-sh/checkout/embed');

        // `onLoaded` en la propia creación y no solo como evento: la ventana puede terminar de cargar
        // antes de que dé tiempo a suscribirse, y entonces el aviso se perdería y el desvío de
        // emergencia saltaría sobre un pago que estaba funcionando.
        checkout = await PolarEmbedCheckout.create(url, {
            theme: 'light',
            onLoaded: () => { cargo = true; },
        });
    } catch {
        // La librería no cargó (red, bloqueador, versión de navegador). El pago no puede depender de
        // que un script llegue.
        window.location.href = url;

        return;
    }

    // El dominio hay que autorizarlo en Polar. Si no lo está, la ventana ABRE Y SE QUEDA EN BLANCO:
    // no lanza ningún error, así que un try/catch no lo detecta. Lo único observable es que el aviso
    // de «cargado» nunca llega.
    checkout.addEventListener('loaded', () => { cargo = true; });

    setTimeout(() => {
        if (cargo) return;

        try { checkout.close(); } catch { /* si ya se cerró, da igual */ }
        window.location.href = url;
    }, ESPERA_DE_CARGA_MS);

    checkout.addEventListener('confirmed', () => alEstado('confirmando'));

    // Pagó. Esto NO activa nada: solo empieza a preguntar si el servidor ya se enteró.
    checkout.addEventListener('success', () => {
        alEstado('confirmando');
        esperarActivacion(alEstado);
    });
}

/**
 * Pide el cobro al servidor y devuelve su dirección, o nada si no se puede seguir.
 *
 * Es común a los dos caminos —la ventana embebida y la página de Polar— porque el cobro SIEMPRE lo
 * crea el servidor: es lo que hace que el aviso de pago sepa a qué empresa activar.
 *
 * @returns {Promise<string|undefined>}
 */
async function pedirCobro(form, { alFallo = () => {} } = {}) {
    try {
        const respuesta = await fetch(form.action, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value ?? '',
            },
        });

        const datos = await respuesta.json().catch(() => ({}));

        // 422: el servidor tiene un motivo concreto y legible (plan sin enlazar, pasarela caída, sin
        // correo de contacto). Se enseña tal cual en vez de mandarlo a la pasarela a chocarse allí.
        if (respuesta.status === 422 && datos.message) {
            alFallo(datos.message);

            return undefined;
        }

        if (!respuesta.ok || !datos.url) throw new Error('sin url');

        return datos.url;
    } catch {
        // Fallo de red o respuesta inesperada: se paga por el camino de siempre.
        form.submit();

        return undefined;
    }
}

/**
 * Pagar en la página de Polar, fuera del panel.
 *
 * Es el único sitio donde hoy salen Apple Pay y Google Pay: en la ventana embebida vienen apagados
 * hasta que Polar autorice el dominio, y en su página aparecen solos según el dispositivo.
 *
 * No hay vuelta programada: al terminar, Polar devuelve al cliente por su cuenta y el plan lo activa
 * el aviso de pago, igual que por el otro camino. Aquí no se sondea nada porque la pestaña ya no es
 * esta.
 */
export async function abrirCobroEnPolar(form, avisos = {}) {
    const url = await pedirCobro(form, avisos);

    if (!url) return;

    window.location.href = url;
}

/**
 * Pregunta al servidor hasta que el aviso de Polar haya activado el plan.
 *
 * El aviso de pago viaja de Polar a nuestro servidor por su cuenta y suele tardar unos segundos. Sin
 * esto, al cliente solo le quedaba recargar la página a mano sin saber cuándo.
 */
async function esperarActivacion(alEstado) {
    const hasta = Date.now() + SONDEO_MAXIMO_MS;

    while (Date.now() < hasta) {
        await new Promise((r) => setTimeout(r, SONDEO_CADA_MS));

        try {
            const res = await fetch(window.rutaEstadoSuscripcion, {
                headers: { Accept: 'application/json' },
            });

            if (res.ok && (await res.json()).activa) {
                alEstado('activo');
                window.location.reload();

                return;
            }
        } catch {
            // Un fallo suelto de red no interrumpe la espera: se reintenta en el siguiente turno.
        }
    }

    alEstado('tarda');
}
