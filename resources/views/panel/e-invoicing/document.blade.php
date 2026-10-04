{{--
    Ficha de un e-CF: datos, archivos guardados (con su huella), respuestas de la DGII y bitácora.
    Las respuestas y la bitácora solo se añaden: lo que se ve aquí es la historia completa.
--}}
<x-layouts.admin :title="$doc->e_ncf" :heading="$doc->e_ncf"
                 :subheading="$doc->ecf_type->value.' · '.$doc->ecf_type->label()">
    <div class="mx-auto max-w-5xl space-y-4">
        <a href="{{ route('panel.e-invoicing.documents') }}" class="text-sm text-slate-500 hover:text-slate-700">← Documentos electrónicos</a>

        @unless ($doc->environment->isFiscal())
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Ambiente <b>{{ $doc->environment->label() }}</b>: este documento no tiene validez fiscal.
            </div>
        @endunless

        <div class="bmos-card bmos-card-pad">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold text-slate-800">Estado</p>
                    <p class="mt-1"><span class="bmos-badge {{ $doc->status->badge() }}">{{ $doc->status->label() }}</span></p>
                    @if ($doc->last_error)
                        <p class="mt-2 max-w-xl text-sm text-rose-600">{{ $doc->last_error }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($puedeReintentar)
                        @can('ecf.send')
                            <form method="POST" action="{{ route('panel.e-invoicing.documents.resend', $doc) }}">
                                @csrf
                                <button type="submit" class="bmos-btn bmos-btn-primary">Reintentar envío</button>
                            </form>
                        @endcan
                    @endif
                    @if ($puedeConsultar)
                        @can('ecf.query')
                            <form method="POST" action="{{ route('panel.e-invoicing.documents.query', $doc) }}">
                                @csrf
                                <button type="submit" class="bmos-btn bmos-btn-ghost">Consultar resultado</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-1 gap-x-6 divide-y divide-slate-100 text-sm sm:grid-cols-2 sm:divide-y-0">
                @foreach ([
                    'Fecha de emisión' => $doc->issue_date?->format('d/m/Y'),
                    'Cliente / proveedor' => trim(($doc->buyer_name ?? 'Consumidor final').' '.($doc->buyer_tax_id ?? '')),
                    'Total' => number_format((float) $doc->total, 2),
                    'ITBIS' => number_format((float) $doc->itbis_total, 2),
                    'Envío' => $doc->sends_summary ? 'Resumen de factura de consumo (RFCE)' : 'e-CF completo',
                    'Código de seguridad' => $doc->security_code ?? '—',
                    'Firmado' => $doc->signed_at?->format('d/m/Y H:i:s') ?? '—',
                    'TrackId' => $doc->track_id ?? '—',
                    'Proveedor' => $doc->provider,
                    'Intentos de envío' => $doc->attempts,
                    'Próximo intento' => $doc->next_attempt_at?->format('d/m/Y H:i') ?? '—',
                    'Esquema' => $doc->spec_version.($doc->schema_date ? ' ('.$doc->schema_date->format('d/m/Y').')' : ''),
                ] as $etiqueta => $valor)
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-slate-500">{{ $etiqueta }}</dt>
                        <dd class="min-w-0 break-all text-right text-slate-800">{{ $valor }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- El timbre (QR + código de seguridad) tal como sale impreso. Hace falta aquí y no solo en el
             PDF: en «En paralelo» el comprobante impreso es la factura B, que no lleva timbre, y esta es
             la única forma de ver el del e-CF de prueba. --}}
        @if ($timbre)
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Timbre (código QR)</p>
                <p class="mt-1 text-xs text-slate-500">Así se imprime en la factura cuando el e-CF es el comprobante. El QR lleva a la consulta de la DGII.</p>
                <div class="mt-2 max-w-md">
                    @include('documents.components.timbre', ['timbre' => $timbre])
                </div>
                <a href="{{ $timbre['url'] }}" target="_blank" rel="noopener noreferrer"
                   class="mt-2 inline-block break-all text-xs font-semibold text-indigo-600 underline">Abrir la consulta de la DGII</a>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Archivos</p>
            <p class="mt-1 text-xs text-slate-500">Guardados tal cual con su huella SHA-256; al descargarlos se comprueba que no cambiaron.</p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @forelse ($doc->files as $f)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="text-slate-800">{{ $f->kind }} <span class="text-xs text-slate-400">· {{ number_format($f->bytes) }} bytes</span></span>
                        <span class="flex items-center gap-3">
                            <span class="hidden font-mono text-xs text-slate-400 sm:inline">{{ substr($f->sha256, 0, 16) }}…</span>
                            @can('ecf.download')
                                <a href="{{ route('panel.e-invoicing.documents.file', [$doc, $f]) }}" class="font-medium text-indigo-600 hover:text-indigo-700">Descargar</a>
                            @endcan
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Sin archivos.</li>
                @endforelse
            </ul>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Respuestas de la DGII / proveedor</p>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                @forelse ($doc->responses as $r)
                    <li class="py-2">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-slate-800">{{ $r->operation }} · {{ $r->outcome }}
                                @if ($r->dgii_code !== null) <span class="text-xs text-slate-400">código {{ $r->dgii_code }}</span> @endif
                            </span>
                            <span class="text-xs text-slate-400">{{ $r->created_at?->format('d/m/Y H:i:s') }}</span>
                        </div>
                        @foreach ((array) $r->messages as $m)
                            <p class="mt-1 text-xs text-slate-600">{{ $m['codigo'] ?? '' }} {{ $m['valor'] ?? '' }}</p>
                        @endforeach
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Todavía no hay respuestas.</li>
                @endforelse
            </ul>
        </div>

        @can('ecf.audit')
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Bitácora</p>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($doc->auditLogs as $l)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="text-slate-800">{{ $l->action }}
                                @if ($l->from_status || $l->to_status)
                                    <span class="text-xs text-slate-400">{{ $l->from_status ?? '—' }} → {{ $l->to_status ?? '—' }}</span>
                                @endif
                            </span>
                            <span class="text-xs text-slate-400">{{ $l->created_at?->format('d/m/Y H:i:s') }}{{ $l->ip ? ' · '.$l->ip : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endcan
    </div>
</x-layouts.admin>
