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
                @php
                    $estadoCert = $certificado?->status();
                    $etiquetaCert = match ($estadoCert) {
                        'vigente' => ['Vigente hasta '.$certificado->valid_to->format('d/m/Y'), 'badge-green'],
                        'por_vencer' => ['Vence el '.$certificado->valid_to->format('d/m/Y'), 'badge-amber'],
                        'vencido' => ['Vencido', 'badge-red'],
                        'aun_no_valido' => ['Aún no es válido', 'badge-amber'],
                        default => ['No configurado', 'badge-gray'],
                    };
                @endphp
                <div class="flex items-center justify-between gap-3 py-2">
                    <dt class="text-slate-500">Certificado digital</dt>
                    <dd><span class="bmos-badge {{ $etiquetaCert[1] }}">{{ $etiquetaCert[0] }}</span></dd>
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
            <p class="font-semibold text-slate-800">Certificado digital</p>
            <p class="mt-1 text-xs text-slate-500">
                El certificado para procesos tributarios con el que se firman los e-CF, emitido por una prestadora acreditada
                por INDOTEL. Se guarda cifrado y la clave privada nunca se muestra.
            </p>

            @if ($certificado)
                <dl class="mt-4 divide-y divide-slate-100 text-sm">
                    <div class="flex items-start justify-between gap-3 py-2">
                        <dt class="shrink-0 text-slate-500">Titular</dt>
                        <dd class="min-w-0 break-words text-right text-slate-800">{{ $certificado->subject }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3 py-2">
                        <dt class="shrink-0 text-slate-500">Emitido por</dt>
                        <dd class="min-w-0 break-words text-right text-slate-600">{{ $certificado->issuer }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="text-slate-500">Válido</dt>
                        <dd class="text-slate-800">{{ $certificado->valid_from->format('d/m/Y') }} – {{ $certificado->valid_to->format('d/m/Y') }}</dd>
                    </div>
                </dl>
            @else
                <p class="mt-4 rounded-lg border border-slate-200 p-3 text-sm text-slate-500">Todavía no hay un certificado cargado.</p>
            @endif

            @can('ecf.configure')
                @if (! $migracionPendiente)
                    <form method="POST" action="{{ route('panel.e-invoicing.certificate.store') }}" enctype="multipart/form-data"
                          class="mt-4 rounded-lg border border-slate-200 p-3">
                        @csrf
                        <p class="text-sm font-medium text-slate-800">{{ $certificado ? 'Reemplazar el certificado' : 'Subir el certificado' }}</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="bmos-field-label" for="cert-archivo">Archivo .p12 o .pfx</label>
                                <input id="cert-archivo" name="certificate" type="file" accept=".p12,.pfx" class="bmos-input">
                            </div>
                            <div>
                                <label class="bmos-field-label" for="cert-clave">Contraseña</label>
                                <input id="cert-clave" name="password" type="password" autocomplete="off" class="bmos-input">
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="bmos-btn bmos-btn-primary">Guardar certificado</button>
                        </div>
                        @error('certificate')
                            <p class="mt-2 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </form>
                @endif
            @endcan
        </div>

        <div class="bmos-card bmos-card-pad">
            <p class="font-semibold text-slate-800">Secuencias de e-NCF</p>
            <p class="mt-1 text-xs text-slate-500">
                Los rangos que la DGII te autorizó en su Oficina Virtual. BMIA no pide números: solo anota los autorizados
                para no salirse de ellos. Cada ambiente tiene los suyos.
            </p>

            @if ($secuencias->isEmpty())
                <p class="mt-4 rounded-lg border border-slate-200 p-3 text-sm text-slate-500">Todavía no hay secuencias registradas.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs text-slate-500">
                                <th class="py-2 pr-3 font-medium">Tipo</th>
                                <th class="py-2 pr-3 font-medium">Ambiente</th>
                                <th class="py-2 pr-3 font-medium">Próximo</th>
                                <th class="py-2 pr-3 font-medium">Quedan</th>
                                <th class="py-2 pr-3 font-medium">Vence</th>
                                <th class="py-2 font-medium">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($secuencias as $s)
                                @php
                                    $estado = match (true) {
                                        ! $s->is_active => ['Inactiva', 'badge-gray'],
                                        $s->isExpired() => ['Vencida', 'badge-red'],
                                        ! $s->hasAvailableNumbers() => ['Agotada', 'badge-red'],
                                        default => ['Activa', 'badge-green'],
                                    };
                                @endphp
                                <tr>
                                    <td class="py-2 pr-3 text-slate-800">{{ $s->ecf_type->prefix() }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $s->environment->label() }}</td>
                                    <td class="py-2 pr-3 font-mono text-xs text-slate-700">{{ $s->hasAvailableNumbers() ? $ncf->format($s->ecf_type, $s->next_number) : '—' }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ number_format($s->remaining()) }}</td>
                                    <td class="py-2 pr-3 text-slate-600">{{ $s->expires_at?->format('d/m/Y') ?? 'No vence' }}</td>
                                    <td class="py-2"><span class="bmos-badge {{ $estado[1] }}">{{ $estado[0] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @can('ecf.configure')
                @if (! $migracionPendiente)
                    <form method="POST" action="{{ route('panel.e-invoicing.sequences.store') }}" class="mt-4 rounded-lg border border-slate-200 p-3">
                        @csrf
                        <p class="text-sm font-medium text-slate-800">Registrar un rango autorizado</p>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label class="bmos-field-label" for="sec-ambiente">Ambiente</label>
                                <select id="sec-ambiente" name="environment" class="bmos-input">
                                    @foreach ($ambientes as $amb)
                                        <option value="{{ $amb->value }}" @selected(old('environment', 'pruebas') === $amb->value)>{{ $amb->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-tipo">Tipo</label>
                                <select id="sec-tipo" name="ecf_type" class="bmos-input">
                                    @foreach ($tipos as $tipo)
                                        <option value="{{ $tipo->value }}" @selected((int) old('ecf_type', 32) === $tipo->value)>{{ $tipo->prefix() }} · {{ $tipo->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="bmos-field-label" for="sec-desde">Desde</label>
                                    <input id="sec-desde" name="range_from" type="number" min="1" value="{{ old('range_from', 1) }}" class="bmos-input">
                                </div>
                                <div>
                                    <label class="bmos-field-label" for="sec-hasta">Hasta</label>
                                    <input id="sec-hasta" name="range_to" type="number" min="1" value="{{ old('range_to') }}" class="bmos-input">
                                </div>
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-autorizada">Autorizada el</label>
                                <input id="sec-autorizada" name="authorized_at" type="date" value="{{ old('authorized_at') }}" class="bmos-input">
                            </div>
                            <div>
                                <label class="bmos-field-label" for="sec-vence">Vence el (vacío si no vence)</label>
                                <input id="sec-vence" name="expires_at" type="date" value="{{ old('expires_at') }}" class="bmos-input">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="bmos-btn bmos-btn-primary w-full justify-center">Registrar</button>
                            </div>
                        </div>
                        @php($erroresSecuencia = collect(['environment', 'ecf_type', 'range_from', 'range_to', 'authorized_at', 'expires_at'])->flatMap(fn ($c) => $errors->get($c)))
                        @if ($erroresSecuencia->isNotEmpty())
                            <ul class="mt-3 space-y-1 text-xs text-rose-600">
                                @foreach ($erroresSecuencia as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </form>
                @endif
            @endcan
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
