{{-- Instalación nativa (Android / Chrome / Edge de escritorio): el navegador dispara
     `beforeinstallprompt` cuando la app es instalable. Se guarda el evento y se muestra un banner
     con botón "Instalar" que lanza el instalador nativo de un toque. En iOS este evento NO existe
     (ahí actúa el aviso de Safari), así que ambos banners nunca aparecen a la vez. --}}
<div id="pwa-install-banner" style="display:none"
     class="fixed inset-x-3 bottom-3 z-50 mx-auto max-w-md rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
    <div class="flex items-center gap-3">
        <img src="{{ asset('images/apple-touch-icon.png') }}" alt="BM Business" class="h-10 w-10 shrink-0 rounded-lg">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-800">Instala BM Business</p>
            <p class="text-xs text-slate-500">Ábrela como app: más rápida y a pantalla completa.</p>
        </div>
        <button type="button" id="pwa-install-btn" class="bmos-btn bmos-btn-primary shrink-0 text-xs">Instalar</button>
        <button type="button" onclick="dismissPwaBanner()" aria-label="Cerrar"
                class="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:1.1rem;height:1.1rem"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>

<script>
    (function () {
        var deferred = null;
        var dismissed = false;
        try { dismissed = localStorage.getItem('pwa_install_banner') === '1'; } catch (e) {}

        var banner = document.getElementById('pwa-install-banner');
        var btn = document.getElementById('pwa-install-btn');

        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();          // evita el mini-infobar por defecto; usamos nuestro botón
            deferred = e;
            if (banner && !dismissed) { banner.style.display = 'block'; document.body.classList.add('con-banner'); }
        });

        if (btn) {
            btn.addEventListener('click', function () {
                if (!deferred) { return; }
                deferred.prompt();
                deferred.userChoice.finally(function () {
                    deferred = null;
                    if (banner) { banner.style.display = 'none'; document.body.classList.remove('con-banner'); }
                });
            });
        }

        // Si ya se instaló, no volver a ofrecerlo.
        window.addEventListener('appinstalled', function () {
            if (banner) { banner.style.display = 'none'; document.body.classList.remove('con-banner'); }
            try { localStorage.setItem('pwa_install_banner', '1'); } catch (e) {}
        });
    })();

    function dismissPwaBanner() {
        var b = document.getElementById('pwa-install-banner');
        if (b) { b.style.display = 'none'; document.body.classList.remove('con-banner'); }
        try { localStorage.setItem('pwa_install_banner', '1'); } catch (e) {}
    }
</script>
