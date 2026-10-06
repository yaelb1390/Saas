{{--
    Diagnóstico de facturación electrónica: cada chequeo con su estado y, si no está bien, cómo
    solucionarlo. Solo lee lo registrado; nunca llama a la DGII.

    Diseño con daisyUI (clases `d-`, tema «bmia» limitado a este contenedor: ver app.css). Los errores
    primero a la vista: el resumen arriba y cada chequeo con el color de su nivel.
--}}
@php
    $correctos = count($chequeos) - $errores - $avisos;
    $nivel = [
        'ok' => ['Correcto', 'd-alert-success', '✓'],
        'aviso' => ['Advertencia', 'd-alert-warning', '!'],
        'error' => ['Error', 'd-alert-error', '✕'],
    ];
@endphp
<x-layouts.admin :back="route('panel.e-invoicing')" :back-label="'Facturación Electrónica'"
                 title="Diagnóstico e-CF" heading="Diagnóstico"
                 subheading="Qué está listo y qué falta para emitir comprobantes electrónicos">
    <div data-theme="bmia" class="bmos-ecf mx-auto max-w-4xl space-y-4">
        <div class="bmos-card bmos-card-pad text-sm text-slate-700">
            @if ($errores === 0 && $avisos === 0)
                <b class="text-slate-800">Todo correcto.</b>
            @else
                <b class="text-slate-800">{{ $errores }} {{ $errores === 1 ? 'error' : 'errores' }} · {{ $avisos }} {{ $avisos === 1 ? 'advertencia' : 'advertencias' }}.</b>
                Empieza por los errores: impiden emitir.
            @endif

            <div class="d-stats d-stats-vertical mt-3 w-full border border-slate-200 sm:d-stats-horizontal">
                <div class="d-stat">
                    <div class="d-stat-title">Errores</div>
                    <div class="d-stat-value text-error">{{ $errores }}</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">Advertencias</div>
                    <div class="d-stat-value text-warning">{{ $avisos }}</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">Correctos</div>
                    <div class="d-stat-value text-success">{{ $correctos }}</div>
                </div>
            </div>
        </div>

        <ul class="space-y-2">
            @foreach ($chequeos as $c)
                <li role="alert" class="d-alert d-alert-soft {{ $nivel[$c['level']][1] }} items-start text-sm">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-current/10 text-xs font-bold">{{ $nivel[$c['level']][2] }}</span>
                    <div class="min-w-0">
                        <p class="font-semibold">
                            {{ $c['title'] }}
                            <span class="ml-1 text-xs font-medium opacity-70">· {{ $nivel[$c['level']][0] }}</span>
                        </p>
                        <p class="mt-0.5 break-words">{{ $c['detail'] }}</p>
                        @if ($c['fix'])
                            <p class="mt-1 text-xs"><b>Cómo solucionarlo:</b> {{ $c['fix'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.admin>
