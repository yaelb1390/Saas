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
