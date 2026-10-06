{{--
    Ficha de un e-CF: datos, archivos guardados (con su huella), respuestas de la DGII y bitácora.
    Las respuestas y la bitácora solo se añaden: lo que se ve aquí es la historia completa.

    Diseño con daisyUI (clases `d-`, tema «bmia» limitado a este contenedor: ver app.css): las cifras
    arriba, y respuestas y bitácora como línea de tiempo para leer la historia de arriba abajo.
--}}
<x-layouts.admin :back="route('panel.e-invoicing.documents')" :back-label="'Documentos electrónicos'"
                 :title="$doc->e_ncf" :heading="$doc->e_ncf"
                 :subheading="$doc->ecf_type->value.' · '.$doc->ecf_type->label()">
    <div data-theme="bmia" class="bmos-ecf mx-auto max-w-5xl space-y-4">
        @unless ($doc->environment->isFiscal())
            <div role="alert" class="d-alert d-alert-soft d-alert-warning text-sm">
                <span>Ambiente <b>{{ $doc->environment->label() }}</b>: este documento no tiene validez fiscal.</span>
            </div>
        @endunless

        <div class="bmos-card bmos-card-pad">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-semibold text-slate-800">Estado</p>
                    <p class="mt-1"><x-panel.ecf-badge :tono="$doc->status->badge()" grande>{{ $doc->status->label() }}</x-panel.ecf-badge></p>
                    @if ($doc->last_error)
                        <div role="alert" class="d-alert d-alert-soft d-alert-error mt-2 max-w-xl text-sm"><span>{{ $doc->last_error }}</span></div>
                    @endif
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($puedeReintentar)
                        @can('ecf.send')
                            <form method="POST" action="{{ route('panel.e-invoicing.documents.resend', $doc) }}">
                                @csrf
                                <button type="submit" class="d-btn d-btn-primary">Reintentar envío</button>
                            </form>
                        @endcan
                    @endif
                    @if ($puedeConsultar)
                        @can('ecf.query')
                            <form method="POST" action="{{ route('panel.e-invoicing.documents.query', $doc) }}">
                                @csrf
                                <button type="submit" class="d-btn d-btn-primary d-btn-soft">Consultar resultado</button>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>

            <div class="d-stats d-stats-vertical mt-4 w-full border border-slate-200 sm:d-stats-horizontal">
                <div class="d-stat">
                    <div class="d-stat-title">Total</div>
                    <div class="d-stat-value text-2xl">{{ number_format((float) $doc->total, 2) }}</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">ITBIS</div>
                    <div class="d-stat-value text-2xl">{{ number_format((float) $doc->itbis_total, 2) }}</div>
                </div>
                <div class="d-stat">
                    <div class="d-stat-title">Intentos de envío</div>
                    <div class="d-stat-value text-2xl">{{ $doc->attempts }}</div>
                    <div class="d-stat-desc">{{ $doc->next_attempt_at ? 'próximo: '.$doc->next_attempt_at->format('d/m/Y H:i') : 'sin reintentos pendientes' }}</div>
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
                   class="d-btn d-btn-sm d-btn-primary d-btn-soft mt-2">Abrir la consulta de la DGII</a>
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Archivos</p>
            <p class="mt-1 text-xs text-slate-500">Guardados tal cual con su huella SHA-256; al descargarlos se comprueba que no cambiaron.</p>
            <ul class="d-list mt-3 text-sm">
                @forelse ($doc->files as $f)
                    <li class="d-list-row items-center">
                        <span class="d-badge d-badge-sm d-badge-neutral d-badge-soft font-mono">XML</span>
                        <span class="text-slate-800">{{ $f->kind }} <span class="text-xs text-slate-400">· {{ number_format($f->bytes) }} bytes</span>
                            <span class="block font-mono text-xs text-slate-400 sm:inline sm:ml-2">{{ substr($f->sha256, 0, 16) }}…</span>
                        </span>
                        @can('ecf.download')
                            <a href="{{ route('panel.e-invoicing.documents.file', [$doc, $f]) }}" class="d-btn d-btn-xs d-btn-primary d-btn-soft">Descargar</a>
                        @endcan
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Sin archivos.</li>
                @endforelse
            </ul>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Respuestas de la DGII / proveedor</p>
            @if ($doc->responses->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Todavía no hay respuestas.</p>
            @else
                <ul class="d-timeline d-timeline-vertical d-timeline-compact mt-3 text-sm">
                    @foreach ($doc->responses as $r)
                        @php
                            $tonoRespuesta = match ($r->outcome) {
                                'accepted', 'accepted_conditional', 'received' => 'text-success',
                                'rejected', 'permanent_error', 'not_configured' => 'text-error',
                                default => 'text-warning',
                            };
                        @endphp
                        <li>
                            @unless ($loop->first) <hr> @endunless
                            <div class="d-timeline-middle {{ $tonoRespuesta }}">●</div>
                            <div class="d-timeline-end d-timeline-box mb-2 w-full">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-slate-800">{{ $r->operation }} · {{ $r->outcome }}
                                        @if ($r->dgii_code !== null) <span class="text-xs text-slate-400">código {{ $r->dgii_code }}</span> @endif
                                    </span>
                                    <span class="text-xs text-slate-400">{{ $r->created_at?->format('d/m/Y H:i:s') }}</span>
                                </div>
                                @foreach ((array) $r->messages as $m)
                                    <p class="mt-1 text-xs text-slate-600">{{ $m['codigo'] ?? '' }} {{ $m['valor'] ?? '' }}</p>
                                @endforeach
                            </div>
                            @unless ($loop->last) <hr> @endunless
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @can('ecf.audit')
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Bitácora</p>
                <ul class="d-timeline d-timeline-vertical d-timeline-compact mt-3 text-sm">
                    @foreach ($doc->auditLogs as $l)
                        <li>
                            @unless ($loop->first) <hr> @endunless
                            <div class="d-timeline-middle text-primary">●</div>
                            <div class="d-timeline-end mb-2 flex w-full flex-wrap items-center justify-between gap-2 pl-1">
                                <span class="text-slate-800">{{ $l->action }}
                                    @if ($l->from_status || $l->to_status)
                                        <span class="text-xs text-slate-400">{{ $l->from_status ?? '—' }} → {{ $l->to_status ?? '—' }}</span>
                                    @endif
                                </span>
                                <span class="text-xs text-slate-400">{{ $l->created_at?->format('d/m/Y H:i:s') }}{{ $l->ip ? ' · '.$l->ip : '' }}</span>
                            </div>
                            @unless ($loop->last) <hr> @endunless
                        </li>
                    @endforeach
                </ul>
            </div>
        @endcan
    </div>
</x-layouts.admin>
