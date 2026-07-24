{{-- Aviso de instalación para iPhone: iOS no muestra un botón "Instalar" automático como Android,
     así que se le explica al usuario cómo añadir la app a la pantalla de inicio. Solo aparece en
     Safari de iOS, cuando la app NO está ya instalada, y se puede descartar (se recuerda). --}}
<div id="ios-install-hint" style="display:none"
     class="fixed inset-x-3 bottom-3 z-50 mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
    <div class="flex items-start gap-3">
        <img src="{{ asset('images/apple-touch-icon.png') }}" alt="BM Business" class="h-10 w-10 shrink-0 rounded-lg">
        <div class="min-w-0 flex-1 text-sm">
            <p class="font-semibold text-slate-800">Instala BM Business en tu iPhone</p>
            <p class="mt-0.5 leading-snug text-slate-500">
                Toca
                <span class="inline-flex items-center gap-0.5 font-medium text-indigo-600">
                    Compartir
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:.95rem;height:.95rem;display:inline"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                </span>
                y luego <b class="text-slate-700">«Añadir a pantalla de inicio»</b>.
            </p>
        </div>
        <button type="button" onclick="dismissIosHint()" aria-label="Cerrar"
                class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>

<script>
    (function () {
        var ua = navigator.userAgent || '';
        var isIos = /iphone|ipad|ipod/i.test(ua);
        // Chrome/Firefox/Edge en iOS no ofrecen "Añadir a pantalla de inicio": el aviso solo aplica a Safari.
        var isOtherIosBrowser = /crios|fxios|edgios/i.test(ua);
        var isStandalone = ('standalone' in window.navigator && window.navigator.standalone) ||
            window.matchMedia('(display-mode: standalone)').matches;
        var dismissed = false;
        try { dismissed = localStorage.getItem('ios_install_hint') === '1'; } catch (e) {}

        if (isIos && !isOtherIosBrowser && !isStandalone && !dismissed) {
            var el = document.getElementById('ios-install-hint');
            if (el) { el.style.display = 'block'; }
        }
    })();

    function dismissIosHint() {
        var el = document.getElementById('ios-install-hint');
        if (el) { el.style.display = 'none'; }
        try { localStorage.setItem('ios_install_hint', '1'); } catch (e) {}
    }
</script>
