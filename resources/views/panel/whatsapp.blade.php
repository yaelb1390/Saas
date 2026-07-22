@php
    // Estado de la línea → etiqueta, tono del indicador, pulso y ayuda contextual.
    [$stateLabel, $stateBadge, $stateTone, $statePulse, $stateHint] = match ($status['state']) {
        'open' => ['En línea', 'badge-green', 'live', true, 'La línea está activa: puedes enviar y recibir mensajes.'],
        'connecting' => ['Emparejando', 'badge-amber', 'pending', true, 'Escanea el código QR con WhatsApp para completar el vínculo.'],
        'close' => ['Sesión cerrada', 'badge-amber', 'pending', false, 'La instancia existe pero la sesión se cerró. Vuelve a emparejar.'],
        'missing' => ['Sin vincular', 'badge-gray', 'idle', false, 'Todavía no has vinculado un teléfono a esta empresa.'],
        'log' => ['Modo registro', 'badge-gray', 'idle', false, 'Evolution API no está configurado: los envíos se guardan, pero no salen.'],
        default => ['Sin conexión', 'badge-red', 'down', false, 'No se pudo contactar con Evolution API.'],
    };

    $qr = session('wa_qr');
@endphp
<x-layouts.admin title="WhatsApp" heading="WhatsApp" subheading="Bandeja de entrada conectada a Evolution API">

    {{-- Estado de la línea --}}
    <div class="bmos-card bmos-card-pad">
        <div class="wa-hero">
            <div class="wa-hero-id">
                <span class="wa-status-orb" data-tone="{{ $stateTone }}" data-pulse="{{ $statePulse ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3q-1.72 0-3.42-.12M3.75 15.75V8.25a2.25 2.25 0 0 1 2.25-2.25h9a2.25 2.25 0 0 1 2.25 2.25v7.5A2.25 2.25 0 0 1 15 18h-3.75l-3 3v-3H6a2.25 2.25 0 0 1-2.25-2.25Z"/>
                    </svg>
                </span>
                <div>
                    <div class="wa-hero-title">
                        Línea de WhatsApp
                        <span class="bmos-badge {{ $stateBadge }}">{{ $stateLabel }}</span>
                    </div>
                    <p class="wa-hero-hint">{{ $stateHint }}</p>
                    <p class="wa-instance">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:.8rem;height:.8rem"><path stroke-linecap="round" stroke-linejoin="round" d="m6.75 7.5 3 2.25-3 2.25m4.5 0h3m-9 8.25h13.5A2.25 2.25 0 0 0 21 18V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v12a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                        instancia {{ $status['instance'] }}
                    </p>
                </div>
            </div>

            @if ($status['state'] !== 'open')
                @can('whatsapp.connect')
                    <form method="POST" action="{{ route('panel.whatsapp.connect') }}">
                        @csrf
                        <button type="submit" class="bmos-btn bmos-btn-primary">
                            {{ $status['state'] === 'missing' ? 'Vincular teléfono' : 'Reintentar vínculo' }}
                        </button>
                    </form>
                @endcan
            @endif
        </div>

        @if ($qr)
            <div class="wa-pair">
                <img src="{{ $qr }}" alt="Código QR para vincular WhatsApp" class="wa-qr">
                <div>
                    <p class="font-semibold text-slate-800">Vincula el teléfono de la empresa</p>
                    <p class="mt-1 mb-4 text-sm text-slate-500">El código caduca en menos de un minuto; si expira, pulsa «Reintentar vínculo».</p>
                    <div class="wa-steps">
                        <p class="wa-step">Abre WhatsApp en el teléfono del negocio.</p>
                        <p class="wa-step">Entra en <strong>Ajustes → Dispositivos vinculados</strong>.</p>
                        <p class="wa-step">Pulsa <strong>Vincular un dispositivo</strong> y escanea este código.</p>
                        <p class="wa-step">Recarga esta página: el estado pasará a <strong>En línea</strong>.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Bandeja: se pinta desde Alpine y se refresca sola (mensajes entrantes y estados de envío). --}}
    <div class="bmos-card wa-shell" x-data="waInbox(@js($inbox), '{{ route('panel.whatsapp.poll') }}')">
        <aside class="wa-aside">
            <div class="wa-panel-head">
                <span class="wa-panel-title">Conversaciones</span>
                <span class="wa-count" x-text="conversations.length"></span>
            </div>

            <div class="wa-list">
                <template x-for="c in conversations" :key="c.phone">
                    <a :href="'{{ route('panel.whatsapp') }}?c=' + c.phone" class="wa-item" :aria-current="c.phone === activePhone ? 'true' : 'false'">
                        <span class="wa-avatar">
                            <template x-if="c.initials"><span x-text="c.initials"></span></template>
                            <template x-if="!c.initials">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0M4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632"/></svg>
                            </template>
                        </span>
                        <span class="wa-item-body">
                            <span class="wa-item-top">
                                <span class="wa-item-name" x-text="c.title"></span>
                                <span class="wa-item-time" x-text="c.time"></span>
                            </span>
                            <span class="wa-item-preview">
                                <span class="text-slate-400" x-show="c.out">Tú:</span>
                                <span x-text="c.preview"></span>
                            </span>
                            <span class="bmos-badge badge-violet mt-1" x-show="c.is_customer">Cliente CRM</span>
                        </span>
                    </a>
                </template>

                <div class="wa-blank" x-show="conversations.length === 0">
                    <span class="wa-blank-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.8 9.8 0 0 1-2.555-.337A5.97 5.97 0 0 1 5.41 20.97a6 6 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                    </span>
                    Aún no hay conversaciones.
                </div>
            </div>
        </aside>

        <section class="wa-thread">
            <div class="wa-thread-head">
                <template x-if="activePhone">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="wa-avatar">
                            <template x-if="activeConversation?.initials"><span x-text="activeConversation.initials"></span></template>
                            <template x-if="!activeConversation?.initials">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.05rem;height:1.05rem"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0M4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.93 17.93 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632"/></svg>
                            </template>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-semibold text-slate-800" x-text="activeConversation?.title ?? activePhone"></p>
                            <p class="wa-instance" style="margin-top:0" x-text="activePhone"></p>
                        </div>
                        <span class="bmos-badge badge-violet ml-auto" x-show="activeConversation?.is_customer">Cliente CRM</span>
                    </div>
                </template>
                <p class="font-semibold text-slate-800" x-show="!activePhone">Nuevo mensaje</p>
            </div>

            <div class="wa-canvas" x-ref="canvas">
                <template x-for="(m, i) in thread" :key="m.id">
                    <div class="wa-row" :class="m.out && 'wa-row--out'" :style="`--i:${Math.min(i, 12)}`">
                        <div class="wa-bubble" :class="m.out ? 'wa-bubble--out' : 'wa-bubble--in'">
                            <p class="wa-text" x-text="m.body"></p>
                            <span class="wa-meta">
                                <span x-text="m.time"></span>
                                <template x-if="m.out && m.status === 'failed'">
                                    <span class="wa-meta-fail">no enviado</span>
                                </template>
                                <template x-if="m.out && m.status === 'pending'">
                                    {{-- En cola: aún no ha salido al proveedor. --}}
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:.75rem;height:.75rem" aria-label="En cola">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                                    </svg>
                                </template>
                                <template x-if="m.out && !['failed', 'pending'].includes(m.status)">
                                    {{-- Un check: enviado. Doble: entregado o leído. --}}
                                    <svg viewBox="0 0 24 20" fill="none" stroke="currentColor" stroke-width="2.4" style="width:.95rem;height:.8rem">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m1 11 4.5 4.5L14 5"/>
                                        <path x-show="['delivered', 'read'].includes(m.status)" stroke-linecap="round" stroke-linejoin="round" d="m9 11 4.5 4.5L22 5"/>
                                    </svg>
                                </template>
                            </span>
                        </div>
                    </div>
                </template>

                <div class="wa-blank" x-show="thread.length === 0">
                    <span class="wa-blank-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.77 59.77 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                    </span>
                    Selecciona una conversación o escribe a un número nuevo.
                </div>
            </div>

            <form method="POST" action="{{ route('panel.whatsapp.send') }}" class="wa-composer">
                @csrf
                <input type="text" name="phone" value="{{ old('phone', $inbox['active_phone']) }}" required
                       placeholder="18095551234" inputmode="numeric" aria-label="Teléfono destino"
                       class="wa-field wa-field--phone {{ $errors->has('phone') ? 'wa-field--error' : '' }}">
                <input type="text" name="body" value="{{ old('body') }}" required maxlength="4096"
                       placeholder="Escribe un mensaje…" autocomplete="off" aria-label="Mensaje"
                       class="wa-field {{ $errors->has('body') ? 'wa-field--error' : '' }}">
                <button type="submit" class="wa-send">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.77 59.77 0 0 1 21.485 12 59.77 59.77 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                    Enviar
                </button>
                @error('phone') <p class="wa-composer-error">{{ $message }}</p> @enderror
                @error('body') <p class="wa-composer-error">{{ $message }}</p> @enderror
            </form>
        </section>
    </div>

    <script>
        function waInbox(initial, pollUrl) {
            return {
                conversations: initial.conversations,
                thread: initial.thread,
                activePhone: initial.active_phone,
                timer: null,

                get activeConversation() {
                    return this.conversations.find((c) => c.phone === this.activePhone);
                },

                init() {
                    this.$nextTick(() => this.toBottom());

                    // Sondeo: los entrantes llegan por webhook y los salientes cambian de estado
                    // al salir de la cola. Se pausa si la pestaña no está visible.
                    this.timer = setInterval(() => {
                        if (document.visibilityState === 'visible') this.refresh();
                    }, 4000);

                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'visible') this.refresh();
                    });
                },

                destroy() {
                    clearInterval(this.timer);
                },

                async refresh() {
                    try {
                        const url = pollUrl + (this.activePhone ? '?c=' + encodeURIComponent(this.activePhone) : '');
                        const res = await fetch(url, { headers: { Accept: 'application/json' } });
                        if (!res.ok) return;

                        const data = await res.json();
                        const grew = data.thread.length > this.thread.length;
                        const nearBottom = this.isNearBottom();

                        this.conversations = data.conversations;
                        this.thread = data.thread;
                        if (!this.activePhone) this.activePhone = data.active_phone;

                        // Solo se auto-desplaza si el usuario ya estaba abajo: no le robamos la
                        // posición mientras lee mensajes antiguos.
                        if (grew && nearBottom) this.$nextTick(() => this.toBottom());
                    } catch {
                        // Un fallo de red puntual no debe romper la bandeja: se reintenta al próximo ciclo.
                    }
                },

                isNearBottom() {
                    const el = this.$refs.canvas;
                    return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
                },

                toBottom() {
                    const el = this.$refs.canvas;
                    el.scrollTop = el.scrollHeight;
                },
            };
        }
    </script>
</x-layouts.admin>
