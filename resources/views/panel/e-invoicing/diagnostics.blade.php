{{--
    Diagnóstico de facturación electrónica: cada chequeo con su estado y, si no está bien, cómo
    solucionarlo. Solo lee lo registrado; nunca llama a la DGII.
--}}
<x-layouts.admin title="Diagnóstico e-CF" heading="Diagnóstico"
                 subheading="Qué está listo y qué falta para emitir comprobantes electrónicos">
    <div class="mx-auto max-w-4xl space-y-4">
        <a href="{{ route('panel.e-invoicing') }}" class="text-sm text-slate-500 hover:text-slate-700">← Facturación Electrónica</a>

        <div class="bmos-card bmos-card-pad text-sm text-slate-700">
            @if ($errores === 0 && $avisos === 0)
                <b class="text-slate-800">Todo correcto.</b>
            @else
                <b class="text-slate-800">{{ $errores }} {{ $errores === 1 ? 'error' : 'errores' }} · {{ $avisos }} {{ $avisos === 1 ? 'advertencia' : 'advertencias' }}.</b>
                Empieza por los errores: impiden emitir.
            @endif
        </div>

        <div class="bmos-card overflow-hidden">
            <ul class="divide-y divide-slate-100">
                @foreach ($chequeos as $c)
                    <li class="flex items-start gap-3 p-4">
                        <span class="bmos-badge shrink-0 {{ ['ok' => 'badge-green', 'aviso' => 'badge-amber', 'error' => 'badge-red'][$c['level']] }}">
                            {{ ['ok' => 'Correcto', 'aviso' => 'Advertencia', 'error' => 'Error'][$c['level']] }}
                        </span>
                        <div class="min-w-0 text-sm">
                            <p class="font-medium text-slate-800">{{ $c['title'] }}</p>
                            <p class="mt-0.5 break-words text-slate-600">{{ $c['detail'] }}</p>
                            @if ($c['fix'])
                                <p class="mt-1 text-xs text-slate-500"><b>Cómo solucionarlo:</b> {{ $c['fix'] }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</x-layouts.admin>
