{{-- Aviso de vencimiento de la suscripción/prueba.

     Lo calcula el SubscriptionNotice (via view composer) y aquí solo se pinta:
     - Un BANNER arriba del contenido mientras el aviso esté vigente (descartable por sesión).
     - Una VENTANA EMERGENTE (modal) solo cuando es crítico (≤3 días), una vez por sesión.

     El descarte se recuerda con sessionStorage keyed por «dismissKey», que cambia con la fecha de
     renovación: al empezar un período nuevo, el aviso vuelve a mostrarse. --}}

@if (isset($subscriptionNotice) && $subscriptionNotice !== null)
    @php
        $n = $subscriptionNotice;
        // Colores por nivel: info (prueba con margen) azul suave, warning ámbar, critical rojo.
        $banner = match ($n->level) {
            'critical' => 'border-rose-200 bg-rose-50 text-rose-800',
            'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
            default => 'border-indigo-200 bg-indigo-50 text-indigo-800',
        };
        $dot = match ($n->level) {
            'critical' => 'text-rose-500',
            'warning' => 'text-amber-500',
            default => 'text-indigo-500',
        };
    @endphp

    <div x-data="subscriptionNotice(@js($n->dismissKey()), @js($n->level))" class="mb-5">
        {{-- Banner --}}
        <div x-show="showBanner" x-cloak
             class="flex items-start gap-3 rounded-xl border px-4 py-3 {{ $banner }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                 class="mt-0.5 h-5 w-5 shrink-0 {{ $dot }}">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M12 3l9 16H3l9-16Z"/>
            </svg>
            <div class="min-w-0 flex-1 text-sm">
                <p class="font-medium">{{ $n->message }}</p>
                <a href="{{ route('panel.account') }}" class="mt-0.5 inline-block font-semibold underline">
                    Ver mi suscripción
                </a>
            </div>
            <button type="button" @click="dismissBanner()" class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100">&times;</button>
        </div>

        {{-- Ventana emergente (solo crítico) --}}
        <div x-show="showModal" x-cloak
             class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-900/60 p-4"
             @keydown.escape.window="dismissModal()">
            <div @click.outside="dismissModal()" class="w-full max-w-md rounded-2xl bg-white p-6 text-center shadow-2xl">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-rose-100">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-6 w-6 text-rose-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M12 3l9 16H3l9-16Z"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-lg font-semibold text-slate-800">
                    {{ $n->isTrial ? 'Tu prueba está por terminar' : 'Tu suscripción está por vencer' }}
                </h3>
                <p class="mt-2 text-sm text-slate-600">{{ $n->message }}</p>
                <div class="mt-5 flex justify-center gap-2">
                    <button type="button" @click="dismissModal()" class="bmos-btn bmos-btn-ghost">Entendido</button>
                    <a href="{{ route('panel.account') }}" class="bmos-btn bmos-btn-primary">Ver mi suscripción</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function subscriptionNotice(dismissKey, level) {
            const bannerKey = 'subnotice-banner-' + dismissKey;
            const modalKey = 'subnotice-modal-' + dismissKey;
            return {
                showBanner: false,
                showModal: false,
                init() {
                    // Se muestra salvo que ya se cerrara en esta sesión (para este mismo vencimiento).
                    this.showBanner = sessionStorage.getItem(bannerKey) !== '1';
                    if (level === 'critical') {
                        this.showModal = sessionStorage.getItem(modalKey) !== '1';
                    }
                },
                dismissBanner() { this.showBanner = false; sessionStorage.setItem(bannerKey, '1'); },
                dismissModal() { this.showModal = false; sessionStorage.setItem(modalKey, '1'); },
            };
        }
    </script>
@endif
