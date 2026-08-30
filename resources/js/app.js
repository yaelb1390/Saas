import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

/**
 * Chart.js se carga BAJO DEMANDA. Antes se importaba arriba y quedaba dentro del bundle principal
 * —que carga en TODAS las páginas, incluido el login y el POS—, arrastrando ~200 KB que la mayoría
 * de pantallas nunca usa. Solo el Dashboard y Reportes dibujan gráficos: con un import() dinámico,
 * Vite lo separa en su propio archivo y el navegador lo descarga solo en esas dos pantallas.
 *
 * Los componentes de gráfico llaman `await window.loadChart()` antes de instanciar.
 */
window.loadChart = async () => {
    if (!window.Chart) {
        const mod = await import('chart.js/auto');
        window.Chart = mod.default;
    }

    return window.Chart;
};

/**
 * AG Grid, cargado BAJO DEMANDA, por el mismo motivo que Chart.js.
 *
 * Es la rejilla del patio de vehículos y pesa bastante más que todo lo demás junto. Solo la abre un
 * dealer; un colmado no verá esa pantalla en su vida y no tiene por qué descargarla al entrar.
 *
 * Se usa la edición Community, que es MIT y gratuita para uso comercial. Agrupar filas,
 * maestro/detalle y Excel son de pago: sin licencia pintan una marca de agua encima de la tabla, y
 * esto es un producto que se vende.
 *
 * SE REGISTRAN LAS PIEZAS SUELTAS Y NO `AllCommunityModule`. Con el paquete entero el trozo pesaba
 * 1,36 MB; con solo lo que se usa baja a la mitad larga. La diferencia la paga el dealer que abre la
 * pantalla con datos móviles, así que se mide y se recorta en vez de meterlo todo por comodidad.
 *
 * Si algún día hace falta una función que no esté aquí, AG Grid avisa en consola diciendo QUÉ módulo
 * falta: no se rompe en silencio.
 *
 * El registro se hace UNA vez: repetirlo en cada visita llenaría la consola de avisos.
 */
window.loadAgGrid = async () => {
    if (!window.agGrid) {
        // Se importa NUESTRO fichero, no el paquete: ahí dentro las importaciones son nombradas y
        // estáticas, que es lo único que permite a Rollup dejar fuera lo que no se usa. Importar el
        // paquete aquí directamente se traía el bulto entero; está medido.
        window.agGrid = await import('./ag-grid.js');
    }

    return window.agGrid;
};

/**
 * El armazón del panel: el cajón del móvil y el plegado del menú.
 *
 * El plegado se RECUERDA en el navegador. Quien vende no esconde el menú una vez: lo esconde porque
 * quiere la pantalla entera para los artículos, y volver a mostrarlo en cada pantalla o en cada
 * recarga sería pelearse con el sistema todo el día.
 *
 * Se guarda por navegador y no en la cuenta a propósito: es una preferencia de ESTE equipo. La misma
 * cajera en la tableta del mostrador y en su portátil quiere cosas distintas.
 *
 * @returns {object} el estado para `x-data`
 */
window.armazonDelPanel = () => ({
    open: false,      // el cajón, solo en móvil
    plegado: false,   // el menú escondido, solo en escritorio

    init() {
        // Con try/catch: en una ventana privada o con las cookies bloqueadas, leer el almacenamiento
        // LANZA, y un panel que no arranca por una preferencia de aspecto sería absurdo.
        try {
            this.plegado = localStorage.getItem('bmos.menu.plegado') === '1';
        } catch (e) {
            this.plegado = false;
        }
    },

    plegar() {
        this.plegado = !this.plegado;

        try {
            localStorage.setItem('bmos.menu.plegado', this.plegado ? '1' : '0');
        } catch (e) {
            // Que no se recuerde es un incordio; que reviente al pulsar, un fallo.
        }
    },
});

/**
 * Escáner por cámara, cargado bajo demanda.
 *
 * Vive aquí y no en el <script> de la vista porque el navegador no sabe resolver un import de
 * «@zxing/browser» por sí solo: eso lo hace Vite. Al usar import() dinámico, el paquete se descarga
 * la primera vez que alguien abre la cámara y no lastra al resto (que escanea con pistola).
 */
window.escanerCamara = async (video, alLeer) => {
    const modulo = await import('./barcode-scanner');

    return modulo.iniciar(video, alLeer);
};

/**
 * Confirmación de borrado de una empresa. Cargada bajo demanda, como Chart.js: solo la usa el panel
 * del operador de la plataforma, y no tiene sentido que el resto del sistema arrastre la librería.
 *
 * Pide DOS cosas porque protegen de riesgos distintos: el nombre responde a «¿es esta empresa?»
 * —conviven nombres casi idénticos en la lista— y la contraseña a «¿eres tú?». El servidor vuelve a
 * comprobar ambas: esto es la puerta, no la cerradura.
 *
 * @param {{nombre: string, usuarios: number, formulario: string}} datos
 */
window.confirmarEliminarEmpresa = async ({ nombre, usuarios, formulario }) => {
    const { default: Swal } = await import('sweetalert2');

    const form = document.getElementById(formulario);
    if (!form) return;

    const esperado = nombre.trim().toLowerCase();

    await Swal.fire({
        icon: 'warning',
        title: `¿Eliminar «${nombre}»?`,
        width: '44rem',
        padding: '2rem',
        buttonsStyling: false,
        reverseButtons: true,
        focusConfirm: false,
        allowOutsideClick: () => !Swal.isLoading(),
        html: `
            <div style="text-align:left">
                <div style="background:#fff1f2;border:1px solid #fecdd3;border-radius:.9rem;padding:1rem 1.15rem;color:#9f1239">
                    <p style="font-weight:700;margin:0 0 .5rem">Se borrará definitivamente:</p>
                    <ul style="margin:0;padding-left:1.2rem;line-height:1.65">
                        <li>Todas sus ventas, facturas, productos, clientes y préstamos</li>
                        <li>Sus ${usuarios} usuarios y sus accesos</li>
                        <li>Sus sucursales, almacenes, cajas y su suscripción</li>
                    </ul>
                    <p style="margin:.75rem 0 0;font-weight:600">No hay copia de seguridad. Esto no se puede deshacer.</p>
                </div>
                <label style="display:block;margin-top:1.25rem;font-weight:600;color:#334155">Escribe el nombre de la empresa</label>
                <input id="swal-nombre" class="swal2-input" style="width:100%;margin:.4rem 0 0" autocomplete="off" placeholder="${nombre}">
                <p style="margin:.35rem 0 0;font-size:.8rem;color:#94a3b8">Para asegurar que es esta y no otra de nombre parecido.</p>
                <label style="display:block;margin-top:1rem;font-weight:600;color:#334155">Tu contraseña</label>
                <input id="swal-clave" type="password" class="swal2-input" style="width:100%;margin:.4rem 0 0" autocomplete="current-password">
            </div>`,
        showCancelButton: true,
        confirmButtonText: 'Eliminar definitivamente',
        cancelButtonText: 'Cancelar',
        customClass: {
            popup: 'bmos-swal',
            title: 'bmos-swal-titulo',
            confirmButton: 'bmos-swal-borrar',
            cancelButton: 'bmos-swal-cancelar',
        },
        preConfirm: () => {
            const escrito = document.getElementById('swal-nombre').value.trim().toLowerCase();
            const clave = document.getElementById('swal-clave').value;

            if (escrito !== esperado) {
                Swal.showValidationMessage('El nombre no coincide con el de la empresa.');
                return false;
            }
            if (!clave) {
                Swal.showValidationMessage('Escribe tu contraseña para confirmar.');
                return false;
            }

            // Se envía aquí dentro y se deja el diálogo cargando: así el botón no admite un
            // segundo clic. Sin esto, un doble clic manda dos borrados y el segundo choca contra
            // una empresa que ya no existe, dando un 404 desconcertante.
            Swal.showLoading();
            form.querySelector('[name="confirm_name"]').value = escrito;
            form.querySelector('[name="password"]').value = clave;
            form.submit();

            return new Promise(() => {}); // nunca resuelve: la página va a navegar
        },
    });
};

/**
 * Aviso flotante del resultado de una acción («15 productos eliminados», «No se pudo guardar»).
 *
 * Es un TOAST, no un diálogo: aparece en una esquina y no bloquea. La diferencia importa, porque
 * esto sale después de cada guardado del panel —decenas de veces al día— y un modal que hay que
 * cerrar convertiría cada acción rutinaria en dos clics.
 *
 * El éxito se va solo con una barra que muestra cuánto queda; el error se queda hasta que lo
 * cierres, porque explica QUÉ corregir y leerlo lleva más tiempo.
 *
 * @param {{tipo: 'success'|'error', titulo: string, texto: string}} aviso
 */
window.avisoFlash = async ({ tipo, titulo, texto }) => {
    const { default: Swal } = await import('sweetalert2');

    const ok = tipo === 'success';

    const toast = Swal.mixin({
        toast: true,
        position: 'top',
        showConfirmButton: false,
        timer: ok ? 4500 : undefined,
        timerProgressBar: ok,
        width: '26rem',
        showClass: { popup: 'bmos-aviso-entra' },
        hideClass: { popup: 'bmos-aviso-sale' },
        customClass: {
            popup: `bmos-aviso ${ok ? 'bmos-aviso--ok' : 'bmos-aviso--error'}`,
            title: 'bmos-aviso-titulo',
        },
        // Al pasar el ratón se detiene la cuenta atrás: si alguien se para a leerlo, no se le
        // escapa a media frase.
        didOpen: (el) => {
            el.addEventListener('mouseenter', Swal.stopTimer);
            el.addEventListener('mouseleave', Swal.resumeTimer);
        },
    });

    await toast.fire({
        icon: tipo,
        title: titulo,
        html: `<p class="bmos-aviso-texto">${texto}</p>`,
        showCloseButton: ! ok,
    });
};

/**
 * Confirmación de una acción, con el aspecto del panel en lugar del diálogo gris del navegador.
 *
 * Es la única de su clase: antes había veinte `confirm()` nativos repartidos por las vistas y tres
 * diálogos casi idénticos aquí. Todos pasan por esta.
 *
 * Sirve a las DOS formas que existen en el proyecto, que es lo que obliga a que devuelva algo:
 *
 *  - Con `formulario`: lo envía y deja el diálogo cargando, de modo que no admite un segundo clic.
 *    Es el caso de los botones de borrar, donde el token y el método vienen del <form> de Blade.
 *  - Sin `formulario`: resuelve `true` o `false`. Lo necesitan los métodos `async` de Alpine del
 *    punto de venta rápido, que preguntan antes de seguir (`if (! await ...) return;`).
 *
 * El TONO no es decoración: rojo para lo que destruye, índigo para lo que no. Archivar un cliente y
 * destruir un plan preguntaban igual, y el color es lo primero que se lee.
 *
 * @param {{
 *   titulo: string,
 *   mensaje?: string,
 *   aviso?: string,
 *   avisoGrave?: boolean,
 *   confirmar?: string,
 *   tono?: 'peligro'|'neutro',
 *   exigirTexto?: string|number,
 *   formulario?: string,
 * }} opciones
 * @returns {Promise<boolean>}
 */
window.confirmarAccion = async ({
    titulo,
    mensaje = '',
    aviso = '',
    avisoGrave = false,
    confirmar = 'Confirmar',
    tono = 'peligro',
    exigirTexto = null,
    formulario = null,
}) => {
    const { default: Swal } = await import('sweetalert2');

    const form = formulario ? document.getElementById(formulario) : null;

    // Un formulario que no aparece significa que la vista cambió y el botón quedó suelto: mejor no
    // abrir un diálogo que al confirmar no haría nada.
    if (formulario && !form) return false;

    const peligro = tono === 'peligro';
    const esperado = exigirTexto === null ? null : String(exigirTexto);

    const bloques = [];

    if (mensaje) {
        bloques.push(`<p style="margin:0">${mensaje}</p>`);
    }

    if (aviso) {
        // El aviso grave va sobre fondo rojo: es el que dice «esto no se puede deshacer», y tiene
        // que distinguirse de una aclaración cualquiera aunque no se lea entero.
        bloques.push(avisoGrave
            ? `<p style="margin:.7rem 0 0;padding:.6rem .8rem;border-radius:.7rem;background:#fff1f2;color:#9f1239;font-weight:600">${aviso}</p>`
            : `<p style="margin:.6rem 0 0;color:#64748b;font-size:.9rem">${aviso}</p>`);
    }

    if (esperado !== null) {
        // Cuando lo que se pide teclear es una cifra, el teclado numérico del móvil ahorra un paso.
        const teclado = /^\d+$/.test(esperado) ? ' inputmode="numeric"' : '';

        bloques.push(`
            <label style="display:block;margin-top:1.15rem;font-weight:600;color:#334155">
                Escribe <b>${esperado}</b> para confirmar
            </label>
            <input id="swal-exigido" class="swal2-input" style="width:100%;margin:.4rem 0 0"${teclado} autocomplete="off">`);
    }

    const { isConfirmed } = await Swal.fire({
        icon: peligro ? 'warning' : 'question',
        title: titulo,
        width: '36rem',
        padding: '2rem',
        buttonsStyling: false,
        reverseButtons: true,
        focusConfirm: false,
        html: bloques.length > 0
            ? `<div style="text-align:left;color:#475569;line-height:1.6">${bloques.join('')}</div>`
            : undefined,
        showCancelButton: true,
        confirmButtonText: confirmar,
        cancelButtonText: 'Cancelar',
        customClass: {
            popup: 'bmos-swal',
            title: 'bmos-swal-titulo',
            confirmButton: peligro ? 'bmos-swal-borrar' : 'bmos-swal-confirmar',
            cancelButton: 'bmos-swal-cancelar',
        },
        preConfirm: () => {
            if (esperado !== null && document.getElementById('swal-exigido').value.trim() !== esperado) {
                Swal.showValidationMessage(`Escribe ${esperado} para confirmar.`);
                return false;
            }

            if (!form) return true;

            // Se envía desde aquí y el diálogo queda cargando: sin esto, un doble clic manda dos
            // peticiones y la segunda choca contra un registro que ya no existe.
            Swal.showLoading();
            form.submit();

            return new Promise(() => {}); // nunca resuelve: la página va a navegar
        },
    });

    return isConfirmed === true;
};

/**
 * Borrado múltiple de productos.
 *
 * El borrado es lógico: se archivan y las ventas ya registradas siguen intactas. Se dice en el
 * diálogo porque es la pregunta que se hace cualquiera antes de pulsar.
 *
 * Al abarcar TODOS los que coinciden con la búsqueda se pide teclear la cifra: marcar unos pocos es
 * un acto deliberado, pero «seleccionar los 500» es un solo clic.
 *
 * @param {{cantidad: number, exigirCifra: boolean, formulario: string}} datos
 */
window.confirmarBorrarProductos = ({ cantidad, exigirCifra, formulario }) => {
    if (cantidad < 1) return Promise.resolve(false);

    const plural = cantidad === 1 ? 'producto' : 'productos';

    return window.confirmarAccion({
        titulo: `¿Eliminar ${cantidad} ${plural}?`,
        mensaje: 'Dejarán de aparecer en el inventario y en el punto de venta.',
        aviso: '<b>Las ventas ya registradas no cambian:</b> los recibos y los informes siguen mostrando lo que se vendió.',
        confirmar: `Eliminar ${cantidad} ${plural}`,
        exigirTexto: exigirCifra ? cantidad : null,
        formulario,
    });
};

/**
 * Anulación múltiple de ventas.
 *
 * Aquí SIEMPRE se pide teclear la cantidad, sin importar cuántas sean: en el inventario unos pocos
 * productos son inofensivos, pero una sola venta ya mueve dinero y existencias.
 *
 * @param {{cantidad: number, formulario: string}} datos
 */
window.confirmarAnularVentas = ({ cantidad, formulario }) => {
    if (cantidad < 1) return Promise.resolve(false);

    const plural = cantidad === 1 ? 'venta' : 'ventas';

    return window.confirmarAccion({
        titulo: `¿Anular ${cantidad} ${plural}?`,
        mensaje: `Al anular se deshace lo que hizo la venta:
            <ul style="margin:.5rem 0 0;padding-left:1.2rem">
                <li>Los productos <b>vuelven al inventario</b>.</li>
                <li>El cobro <b>sale de la caja</b>, así que el arqueo del día cambia.</li>
                <li>Deja de contar en los informes de ventas y ganancias.</li>
            </ul>`,
        aviso: 'Las ventas con factura fiscal o con el arqueo ya cerrado se saltan y te lo indicamos.',
        confirmar: `Anular ${cantidad} ${plural}`,
        exigirTexto: cantidad,
        formulario,
    });
};

/**
 * Cobro de la suscripción en una ventana sobre el propio panel, en vez de mandar al cliente a
 * polar.sh. Bajo demanda, como Chart.js y la cámara: solo lo usa la pantalla de cuenta, y no tiene
 * sentido que el punto de venta arrastre la librería de pagos.
 *
 * @param {HTMLFormElement} form  El formulario de pago, que sigue siendo el camino de reserva.
 */
window.abrirCobroSuscripcion = async (form, avisos) => {
    const modulo = await import('./polar-checkout');

    return modulo.abrirCobro(form, avisos);
};

/**
 * El mismo cobro, pero en la página de Polar en vez de en la ventana embebida.
 *
 * Existe por Apple Pay y Google Pay: en la ventana embebida vienen apagados hasta que Polar autorice
 * el dominio, y en su página salen solos.
 *
 * @param {HTMLFormElement} form  El mismo formulario de pago.
 */
window.abrirCobroEnPolar = async (form, avisos) => {
    const modulo = await import('./polar-checkout');

    return modulo.abrirCobroEnPolar(form, avisos);
};

/**
 * Componente del visor de cámara. Se registra aquí (y no como función suelta en cada vista) porque
 * lo comparten el POS y la entrada de mercancía: definirlo dos veces sería la misma duplicación que
 * evitamos en el backend.
 *
 * No sabe qué hacer con el código: lo anuncia con el evento «codigo-escaneado» y cada pantalla
 * decide. Así el visor no conoce ni el ticket ni el almacén.
 */
Alpine.data('visorCamara', () => ({
    abierto: false,
    error: '',
    cargando: false,
    controles: null,

    async abrir() {
        this.abierto = true;
        this.error = '';
        this.cargando = true;

        try {
            this.controles = await window.escanerCamara(this.$refs.video, (codigo) => {
                // Una lectura basta: se apaga la cámara y se anuncia el código. Sin esto, ZXing
                // seguiría disparando el mismo código en cada fotograma.
                this.cerrar();
                this.$dispatch('codigo-escaneado', { codigo });
            });
        } catch (e) {
            // Falta de HTTPS o permiso denegado: hay que decirlo, no dejar un visor en negro.
            this.error = e?.message ?? 'No se pudo abrir la cámara.';
        } finally {
            this.cargando = false;
        }
    },

    cerrar() {
        // Apagar el stream siempre, o la cámara se queda encendida tras cerrar el modal.
        this.controles?.detener();
        this.controles = null;
        this.abierto = false;
    },

    destroy() {
        this.cerrar();
    },
}));

/**
 * Secciones plegables del menú lateral.
 *
 * El estado se guarda en el navegador porque el panel navega con recargas completas: sin persistir,
 * cada clic reabriría todas las secciones y plegarlas no serviría de nada.
 *
 * Por defecto están todas abiertas —es como se veía el menú hasta ahora, y esconder de golpe todo
 * lo que alguien tenía a la vista es peor que pedirle un clic—. Cada quien pliega lo que no usa.
 */
Alpine.data('menuLateral', (seccionActiva = null) => ({
    cerradas: [],

    init() {
        try {
            this.cerradas = JSON.parse(localStorage.getItem('bmos-nav-cerradas') ?? '[]');
        } catch (e) {
            // localStorage bloqueado o con basura: se arranca con todo abierto.
            this.cerradas = [];
        }

        // La sección de la pantalla actual se abre siempre: dejarla plegada escondería al usuario
        // dónde está parado.
        if (seccionActiva) {
            this.cerradas = this.cerradas.filter((s) => s !== seccionActiva);
        }
    },

    abierta(seccion) {
        return !this.cerradas.includes(seccion);
    },

    alternar(seccion) {
        this.cerradas = this.abierta(seccion)
            ? [...this.cerradas, seccion]
            : this.cerradas.filter((s) => s !== seccion);

        try {
            localStorage.setItem('bmos-nav-cerradas', JSON.stringify(this.cerradas));
        } catch (e) {
            // Sin almacenamiento (modo privado estricto) el menú sigue funcionando, solo que no
            // recuerda el estado entre páginas.
        }
    },
}));

/**
 * Terminal de venta a pantalla completa.
 *
 * El navegador solo concede pantalla completa dentro de un gesto del usuario, así que esto vive
 * detrás de un botón y nunca se activa solo.
 *
 * Ojo con la duración: el modo se pierde en cualquier recarga. Por eso el cobro de la venta rápida
 * se hace por fetch (no recarga) y por eso existe además `manifest-pos.json`, que instalado como
 * aplicación da pantalla completa de verdad, sin depender de esta API.
 */
Alpine.data('kiosko', () => ({
    activa: false,
    soportado: false,
    instalada: false,

    init() {
        this.soportado = !!document.documentElement.requestFullscreen;
        // Ya lanzado como app instalada: la pantalla ya es completa y el botón sobra.
        this.instalada = window.matchMedia('(display-mode: fullscreen)').matches
            || window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;

        this.activa = !!document.fullscreenElement;

        // El usuario puede salir con Escape o F11 sin tocar el botón: hay que seguir el estado real
        // del navegador, no el que creemos tener.
        this._sync = () => { this.activa = !!document.fullscreenElement; };
        document.addEventListener('fullscreenchange', this._sync);
    },

    destroy() {
        document.removeEventListener('fullscreenchange', this._sync);
    },

    async alternar() {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch (e) {
            // Denegado por el navegador (falta de gesto, permisos, iframe): se deja como estaba en
            // vez de romper la pantalla de cobro.
        }
    },
}));

/**
 * Lo que hace falta para cobrar sin internet, disponible para las dos pantallas del POS.
 *
 * Se carga BAJO DEMANDA, como Chart.js y por el mismo motivo: son unos kilobytes que solo usan dos
 * pantallas de quince, y el bundle principal lo descarga hasta quien entra a mirar un informe.
 *
 * Cada pantalla del POS llama `await window.cargarOffline()` al arrancar. Si el navegador no puede
 * guardar nada —navegación privada, almacenamiento bloqueado— devuelve null y el terminal NO ofrece
 * cobrar sin conexión: prometer que la venta se guarda y perderla es peor que decirlo de entrada.
 */
window.cargarOffline = async () => {
    if (window.bmosOffline !== undefined) return window.bmosOffline;

    try {
        const [almacen, cola, recibo] = await Promise.all([
            import('./offline/almacen'),
            import('./offline/cola'),
            import('./offline/recibo'),
        ]);

        window.bmosOffline = (await almacen.disponible())
            ? { almacen, cola, recibo }
            : null;
    } catch {
        window.bmosOffline = null;
    }

    return window.bmosOffline;
};

Alpine.start();

/**
 * Registra el service worker (hace la app instalable y acelera la carga de assets).
 * Se hace tras «load» para no competir con el primer render.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Si falla el registro, la app funciona igual; solo se pierde el cacheo de estáticos.
        });
    });
}
