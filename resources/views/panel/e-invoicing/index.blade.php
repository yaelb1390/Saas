{{--
    Facturación Electrónica (e-CF) — resumen de la empresa activa.

    Estilo de ajustes, igual que «Mi empresa»: secciones tituladas y color solo para estados. Esta
    pantalla no llama a la DGII: enseña lo último registrado. Ver docs/FACTURACION_ELECTRONICA.md.
--}}
<x-layouts.admin title="Facturación Electrónica" heading="Facturación Electrónica"
                 subheading="Comprobantes fiscales electrónicos (e-CF) ante la DGII">
    <div class="mx-auto max-w-4xl space-y-4">

        {{-- Aviso fijo. No se puede cerrar a propósito: es lo único que no debe malinterpretarse. --}}
        <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-600">
            <b class="text-slate-800">Esto no es una autorización de la DGII.</b>
            BMIA prepara y valida tus comprobantes electrónicos, pero solo la DGII autoriza a una empresa a emitir e-CF,
            después de su proceso de certificación.
        </div>

        @if ($migracionPendiente)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                La base de datos todavía no tiene las tablas de facturación electrónica. El administrador de la
                plataforma debe aplicar las migraciones pendientes.
            </div>
        @endif

        @if ($ajustes && ! $ajustes->environment->isFiscal())
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Ambiente: <b>{{ $ajustes->environment->label() }}</b>. Nada de lo que se emita aquí tiene validez fiscal.
            </div>
        @endif

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Estado</p>
            <p class="mt-1 text-xs text-slate-500">Cómo está tu empresa para emitir comprobantes electrónicos.</p>

            <dl class="mt-4 divide-y divide-slate-100 text-sm">
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Estado</dt>
                    <dd>
                        @if ($ajustes)
                            <span class="bmos-badge {{ $ajustes->status->badge() }}">{{ $ajustes->status->label() }}</span>
                        @else
                            <span class="bmos-badge badge-gray">No disponible</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Empresa</dt>
                    <dd class="text-right text-slate-800">{{ $ajustes?->legal_name ?: $empresa->name }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">RNC</dt>
                    <dd class="text-slate-800">{{ $ajustes?->tax_id ?: 'Sin configurar' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Ambiente</dt>
                    <dd class="text-slate-800">{{ $ajustes?->environment->label() ?? '—' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Usuario administrador e-CF</dt>
                    <dd class="text-slate-800">{{ $ajustes?->ecf_admin_user ?: 'Sin configurar' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Certificado digital</dt>
                    <dd class="text-slate-800">No configurado</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Última comunicación con la DGII</dt>
                    <dd class="text-slate-800">{{ $ajustes?->last_dgii_contact_at?->diffForHumans() ?? 'Ninguna todavía' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Documentos pendientes · aceptados · rechazados</dt>
                    <dd class="text-slate-800">0 · 0 · 0</dd>
                </div>
            </dl>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Requisitos técnicos</p>
            <p class="mt-1 text-xs text-slate-500">Lo que el servidor necesita para generar, validar y firmar e-CF.</p>

            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($requisitos as $req)
                    <li class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                        <span class="min-w-0">
                            <span class="block font-medium text-slate-800">Extensión {{ $req['extension'] }}</span>
                            <span class="block text-xs text-slate-500">{{ $req['motivo'] }}</span>
                        </span>
                        <span class="bmos-badge {{ $req['ok'] ? 'badge-green' : 'badge-red' }}">{{ $req['ok'] ? 'Disponible' : 'Falta' }}</span>
                    </li>
                @endforeach

                @php($esquemasOk = collect($esquemas)->where('estado', 'ok')->count())
                <li class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 p-3">
                    <span class="min-w-0">
                        <span class="block font-medium text-slate-800">Esquemas oficiales de la DGII (versión {{ config('ecf.spec.version') }})</span>
                        <span class="block text-xs text-slate-500">{{ $esquemasOk }} de {{ count($esquemas) }} intactos, tal como los publica la DGII.</span>
                    </span>
                    <span class="bmos-badge {{ $esquemasIntegros ? 'badge-green' : 'badge-red' }}">{{ $esquemasIntegros ? 'Correctos' : 'Revisar' }}</span>
                </li>
            </ul>
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Tipos de comprobante</p>
            <p class="mt-1 text-xs text-slate-500">Los e-CF que define la DGII. Se habilitarán por fases.</p>

            <ul class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                @foreach ($tipos as $tipo)
                    <li class="rounded-lg border border-slate-200 p-3">
                        <span class="font-medium text-slate-800">{{ $tipo->prefix() }}</span>
                        <span class="text-slate-600">· {{ $tipo->label() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($pendientes !== [])
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">Pendiente de confirmar con la DGII</p>
                <p class="mt-1 text-xs text-slate-500">
                    La documentación oficial no lo aclara. Lo que dependa de esto no se implementa por suposición.
                </p>
                <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-600">
                    @foreach ($pendientes as $pendiente)
                        <li>{{ $pendiente }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts.admin>
