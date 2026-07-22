import './bootstrap';

import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

window.Chart = Chart;
window.Alpine = Alpine;

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
