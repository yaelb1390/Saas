@php
    use App\Modules\Core\Support\ModuleRegistry;

    $plan = $subscription?->plan;
    $renews = $subscription?->renewsAt();
    $days = $subscription?->daysUntilRenewal();
    $threshold = $plan?->billing_cycle->noticeThresholdDays() ?? 7;
    $soon = $days !== null && $days >= 0 && $days <= $threshold;
    $isTrial = (bool) $subscription?->isTrialing();

    // Progreso de la prueba (solo si es prueba y el plan la define): % de días ya consumidos.
    $trialTotal = (int) ($plan?->trial_days ?? 0);
    $trialPct = null;
    if ($isTrial && $trialTotal > 0 && $days !== null) {
        $elapsed = max(0, $trialTotal - max(0, $days));
        $trialPct = min(100, max(0, (int) round($elapsed / $trialTotal * 100)));
    }
@endphp
<x-layouts.admin title="Mi suscripción" heading="Mi suscripción" subheading="Estado del plan de tu empresa">
    <div class="mx-auto max-w-2xl">
        @if ($subscription === null)
            <div class="bmos-card bmos-card-pad flex flex-col items-center py-12 text-center">
                <span class="mb-4 grid h-14 w-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-500">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-7 w-7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </span>
                <p class="font-semibold text-slate-700">Tu empresa aún no tiene una suscripción</p>
                <p class="mt-1 text-sm text-slate-400">Contacta con el administrador de la plataforma para activar un plan.</p>
            </div>
        @else
            {{-- Tarjeta principal con cabecera en degradado corporativo. --}}
            <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-900/5">
                <div class="relative overflow-hidden bg-gradient-to-br from-indigo-600 via-indigo-600 to-violet-600 p-6 text-white">
                    {{-- Halo ambiental muy tenue. --}}
                    <div class="pointer-events-none absolute -right-10 -top-14 h-40 w-40 rounded-full bg-white/10 blur-2xl"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 place-items-center rounded-xl bg-white/15 ring-1 ring-white/20">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.12 4.94 5.36.46c.5.04.7.66.32.98l-4.07 3.5 1.22 5.24c.11.48-.41.86-.84.6L12 16.9l-4.65 2.82c-.43.26-.95-.12-.84-.6l1.22-5.24-4.07-3.5a.56.56 0 0 1 .32-.98l5.36-.46L11.48 3.5Z"/></svg>
                            </span>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-white/70">Plan actual</p>
                                <p class="text-2xl font-bold leading-tight">{{ $plan?->name ?? 'Sin plan' }}</p>
                            </div>
                        </div>
                        <span class="shrink-0 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold ring-1 ring-white/25">
                            {{ $subscription->status->label() }}
                        </span>
                    </div>

                    @if ($trialPct !== null)
                        <div class="relative mt-6">
                            <div class="flex items-center justify-between text-xs font-medium text-white/85">
                                <span>Período de prueba</span>
                                <span>
                                    @if ($days < 0) Vencida
                                    @elseif ($days === 0) Termina hoy
                                    @else Te quedan {{ $days }} {{ $days === 1 ? 'día' : 'días' }}
                                    @endif
                                </span>
                            </div>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/20">
                                <div class="h-full rounded-full bg-white transition-all" style="width: {{ $trialPct }}%"></div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Datos clave en tiles con icono. --}}
                <div class="grid grid-cols-1 divide-y divide-slate-100 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <div class="p-5">
                        <span class="mb-2.5 grid h-9 w-9 place-items-center rounded-lg bg-indigo-50 text-indigo-600">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0-.83.67-1.5 1.5-1.5h6.44c.4 0 .78.16 1.06.44l8.69 8.69a1.5 1.5 0 0 1 0 2.12l-4.94 4.94a1.5 1.5 0 0 1-2.12 0l-8.69-8.69a1.5 1.5 0 0 1-.44-1.06V6.75Z"/></svg>
                        </span>
                        <p class="bmos-stat-label">Precio</p>
                        <p class="text-lg font-bold text-slate-800">
                            {{ $plan ? number_format((float) $plan->price, 2) : '—' }}
                            <span class="text-xs font-normal text-slate-400">{{ $plan?->billing_cycle->label() }}</span>
                        </p>
                    </div>
                    <div class="p-5">
                        <span class="mb-2.5 grid h-9 w-9 place-items-center rounded-lg {{ $soon ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                        </span>
                        <p class="bmos-stat-label">{{ $isTrial ? 'Prueba hasta' : 'Renueva' }}</p>
                        <p class="text-lg font-bold text-slate-800">{{ $renews?->format('d/m/Y') ?? '—' }}</p>
                        @if ($days !== null)
                            <p class="text-xs font-medium {{ $days < 0 ? 'text-rose-500' : ($soon ? 'text-amber-600' : 'text-slate-400') }}">
                                @if ($days < 0)
                                    Vencido hace {{ abs($days) }} {{ abs($days) === 1 ? 'día' : 'días' }}
                                @elseif ($days === 0)
                                    Vence hoy
                                @else
                                    {{ $isTrial ? 'Te quedan' : 'Vence en' }} {{ $days }} {{ $days === 1 ? 'día' : 'días' }}
                                @endif
                            </p>
                        @endif
                    </div>
                    <div class="p-5">
                        <span class="mb-2.5 grid h-9 w-9 place-items-center rounded-lg bg-violet-50 text-violet-600">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                        </span>
                        <p class="bmos-stat-label">Módulos</p>
                        <p class="text-lg font-bold text-slate-800">{{ count($plan?->moduleKeys() ?? []) }}</p>
                        <p class="text-xs text-slate-400">incluidos en el plan</p>
                    </div>
                </div>

                @unless ($subscription->isUsable())
                    <div class="flex items-start gap-2.5 border-t border-rose-100 bg-rose-50/70 p-4 text-sm text-rose-700">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="mt-0.5 h-5 w-5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                        <span>Tu suscripción no está al día. Realiza el pago y el administrador lo registrará para reactivar el acceso.</span>
                    </div>
                @endunless
            </div>

            @if ($plan)
                <div class="mt-4 bmos-card bmos-card-pad">
                    <p class="mb-3 text-sm font-semibold text-slate-600">Módulos de tu plan</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($plan->moduleKeys() as $key)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200/70">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-indigo-500"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                {{ ModuleRegistry::label($key) }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Acciones para activar/renovar: contacto directo y pago. --}}
            @php
                $waDigits = preg_replace('/\D/', '', $supportWhatsapp);
                $waText = rawurlencode(
                    'Hola, soy de «'.($company?->name ?? 'mi empresa').'». Quiero '
                    .($isTrial ? 'activar' : 'renovar').' mi plan'.($plan ? ' '.$plan->name : '').'.'
                );
                $mailSubject = rawurlencode(($isTrial ? 'Activar' : 'Renovar').' mi suscripción'.($company?->name ? ' — '.$company->name : ''));
                $hasActions = $waDigits || $supportEmail || $supportPaypal;
            @endphp
            <div class="mt-4 overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-50/70 to-white p-5 ring-1 ring-indigo-100">
                <div class="flex items-start gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-indigo-600 ring-1 ring-indigo-100">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3M3.75 5.25h16.5a1.5 1.5 0 0 1 1.5 1.5v10.5a1.5 1.5 0 0 1-1.5 1.5H3.75a1.5 1.5 0 0 1-1.5-1.5V6.75a1.5 1.5 0 0 1 1.5-1.5Z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-800">
                            {{ $isTrial ? '¿Listo para activar tu plan?' : '¿Quieres renovar tu suscripción?' }}
                        </p>
                        <p class="mt-0.5 text-sm text-slate-500">Contáctanos o realiza tu pago y activamos tu cuenta enseguida.</p>
                    </div>
                </div>

                @if ($hasActions)
                    <div class="mt-4 flex flex-wrap gap-3">
                        @if ($waDigits)
                            <a href="https://wa.me/{{ $waDigits }}?text={{ $waText }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 hover:shadow">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884"/></svg>
                                WhatsApp
                            </a>
                        @endif
                        @if ($supportEmail)
                            <a href="mailto:{{ $supportEmail }}?subject={{ $mailSubject }}"
                               class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 hover:shadow">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                Correo
                            </a>
                        @endif
                        @if ($supportPaypal)
                            <a href="{{ $supportPaypal }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 rounded-xl bg-[#0070ba] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#005ea6] hover:shadow">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.048.288-.076.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 0 0-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 0 0-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 0 0 .554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 0 1 .923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                                Pagar con PayPal
                            </a>
                        @endif
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-400">Contacta con el administrador de la plataforma para activar tu plan.</p>
                @endif
            </div>
        @endif
    </div>
</x-layouts.admin>
