{{--
    Icono «Instalar la aplicación» de la barra superior.

    Sirve en TODOS los equipos, aunque solo algunos navegadores sepan instalar con un clic:

      · Chrome, Edge y Android disparan `beforeinstallprompt`: el clic abre el instalador nativo.
        El evento lo guarda el banner (`partials/pwa-install-banner`) en `window.bmosInstalarEvento`
        y ambos lo comparten, porque solo se puede usar una vez.
      · iPhone/iPad, Safari de Mac y Firefox no tienen ese evento: el clic abre un diálogo con los
        pasos de ESE navegador, en vez de un botón que no hace nada.

    Si la app ya está abierta como instalada (pantalla completa), el icono no aparece.
--}}
<div x-data="instalarApp()" x-show="!instalada" x-cloak>
    <button type="button" @click="instalar()" title="Instalar la aplicación" aria-label="Instalar la aplicación"
            class="flex h-9 w-9 items-center justify-center rounded-full text-white/90 transition hover:bg-white/15 hover:text-white">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
        </svg>
    </button>

    {{-- Fuera de la barra: la barra puede crear su propio contexto de apilamiento y un `fixed` de
         dentro quedaría recortado por ella. --}}
    <template x-teleport="body">
        <div x-show="ayuda" x-cloak x-transition.opacity @keydown.escape.window="ayuda = false"
             class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/50 p-4"
             @click.self="ayuda = false">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="instalar-titulo">
                <div class="flex items-center gap-3">
                    <img src="{{ asset('images/apple-touch-icon.png') }}" alt="" class="h-11 w-11 shrink-0 rounded-xl">
                    <div>
                        <p id="instalar-titulo" class="font-semibold text-slate-800">Instala BM Business</p>
                        <p class="text-xs text-slate-500">Se abre como app, a pantalla completa y más rápida.</p>
                    </div>
                </div>

                <ol class="mt-5 space-y-2 text-sm text-slate-600">
                    <template x-for="(paso, i) in pasos()" :key="i">
                        <li class="flex gap-2.5">
                            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600" x-text="i + 1"></span>
                            <span x-html="paso"></span>
                        </li>
                    </template>
                </ol>

                <button type="button" @click="ayuda = false" class="bmos-btn bmos-btn-primary mt-6 w-full justify-center">Entendido</button>
            </div>
        </div>
    </template>
</div>

<script>
    function instalarApp() {
        const ua = navigator.userAgent || '';
        const ios = /iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        const android = /android/i.test(ua);
        const firefox = /firefox|fxios/i.test(ua);
        const safariMac = /Macintosh/.test(ua) && /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR|Firefox/.test(ua) && !ios;

        return {
            ayuda: false,
            instalada: window.matchMedia('(display-mode: standalone)').matches
                || window.matchMedia('(display-mode: fullscreen)').matches
                || window.navigator.standalone === true,

            init() {
                window.addEventListener('appinstalled', () => { this.instalada = true; });
            },

            async instalar() {
                const evento = window.bmosInstalarEvento;

                if (!evento) {
                    this.ayuda = true;
                    return;
                }

                // El evento solo se puede usar una vez: se suelta antes de lanzarlo para que el banner
                // tampoco intente reutilizarlo.
                window.bmosInstalarEvento = null;
                evento.prompt();

                const { outcome } = await evento.userChoice;
                const banner = document.getElementById('pwa-install-banner');
                if (banner) banner.style.display = 'none';
                if (outcome === 'accepted') this.instalada = true;
            },

            // Texto fijo, escrito aquí; nada que venga del usuario pasa por x-html.
            pasos() {
                if (ios) {
                    return [
                        'Toca el botón <b>Compartir</b> (el cuadrado con una flecha hacia arriba).',
                        'Elige <b>«Añadir a pantalla de inicio»</b>.',
                        'Toca <b>Añadir</b>. La app aparece con su icono junto a las demás.',
                    ];
                }
                if (safariMac) {
                    return [
                        'En la barra de menús, abre <b>Archivo</b>.',
                        'Elige <b>«Añadir al Dock»</b>.',
                        'Confirma con <b>Añadir</b>.',
                    ];
                }
                if (firefox && !android) {
                    return [
                        'Firefox de computadora no instala aplicaciones web.',
                        'Abre esta misma dirección en <b>Chrome</b> o <b>Edge</b>.',
                        'Pulsa el icono de descargar de esta barra y luego <b>Instalar</b>.',
                    ];
                }
                if (android) {
                    return [
                        'Abre el menú del navegador (<b>⋮</b>, arriba a la derecha).',
                        'Toca <b>«Instalar aplicación»</b> o <b>«Añadir a pantalla de inicio»</b>.',
                        'Confirma con <b>Instalar</b>.',
                    ];
                }
                return [
                    'Busca el icono de <b>instalar</b> al final de la barra de direcciones, o abre el menú del navegador (<b>⋮</b> o <b>…</b>).',
                    'Elige <b>«Instalar BM Business»</b> (en Edge: <b>Aplicaciones → Instalar este sitio como aplicación</b>).',
                    'Si no aparece la opción, puede que ya esté instalada: búscala en tu escritorio o menú de inicio.',
                ];
            },
        };
    }
</script>
