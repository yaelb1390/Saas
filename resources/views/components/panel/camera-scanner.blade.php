{{--
    Botón y visor para leer un código con la cámara.

    Es una alternativa al lector de pistola, no su sustituto: leer un código 1D con la cámara de un
    móvil es más lento y falla más (enfoque, movimiento, luz, etiquetas gastadas). Rinde bien para
    inventariar a pie de estantería, no en la cola de la caja.

    No sabe qué hacer con lo que lee: emite el evento «codigo-escaneado» y quien lo use decide.
--}}
<div x-data="visorCamara" @keydown.escape.window="cerrar()" class="mt-3">
    <button type="button" @click="abrir()" class="bmos-btn bmos-btn-ghost text-sm">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem" class="mr-1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z"/>
        </svg>
        Usar cámara
    </button>

    {{-- z-[60] y @click.stop: la cámara puede abrirse encima de otro modal (p. ej. «Nuevo
         producto»); así queda por encima y sus clics no cierran el formulario de atrás. --}}
    <div x-show="abierto" x-cloak @click.stop
         class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/70 p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="font-semibold text-slate-800">Escanear con la cámara</h3>
                <button type="button" @click="cerrar()" class="text-slate-400 hover:text-slate-600">✕</button>
            </div>

            <div class="relative overflow-hidden rounded-xl bg-slate-900">
                {{-- html5-qrcode inyecta aquí su propio vídeo. El contenedor necesita tamaño. --}}
                <div x-ref="video" class="min-h-[260px] w-full [&_video]:w-full [&_video]:object-cover"></div>

                <p x-show="cargando" x-cloak
                   class="absolute inset-0 flex items-center justify-center text-sm text-white">
                    Abriendo la cámara...
                </p>
            </div>

            <p x-show="error" x-cloak x-text="error"
               class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800"></p>

            <p x-show="!error" class="mt-3 text-xs text-slate-500">
                Apunta al código de barras. Se cerrará sola al leerlo.
            </p>
        </div>
    </div>
</div>
