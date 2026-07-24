@use('App\Modules\Loans\Enums\LoanStatus')

<x-layouts.admin title="{{ $customer->name }}" heading="{{ $customer->name }}" subheading="Perfil del cliente">
    <div class="mb-4">
        <a href="{{ route('panel.customers') }}" class="text-sm text-slate-500 hover:text-indigo-600">&larr; Volver al CRM</a>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Datos --}}
        <div class="lg:col-span-1">
            <div class="bmos-card bmos-card-pad">
                <div class="flex items-center gap-3">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-base font-bold text-white">
                        {{ mb_strtoupper(mb_substr($customer->name, 0, 1)) }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold text-slate-800">{{ $customer->name }}</p>
                        <p class="text-xs text-slate-400">{{ $customer->is_active ? 'Activo' : 'Inactivo' }}</p>
                    </div>
                </div>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Cédula</dt><dd class="text-right font-medium">{{ $customer->cedula ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Teléfono</dt><dd class="text-right">{{ $customer->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Correo</dt><dd class="truncate text-right">{{ $customer->email ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">RNC</dt><dd class="text-right">{{ $customer->tax_id ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Dirección</dt><dd class="text-right">{{ $customer->address ?? '—' }}</dd></div>
                </dl>
            </div>
        </div>

        {{-- Documentos + préstamos --}}
        <div class="space-y-5 lg:col-span-2">
            <div class="bmos-card overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-100 p-4">
                    <p class="font-semibold text-slate-800">Documentos</p>
                </div>

                @can('customers.manage')
                    <form method="POST" action="{{ route('panel.customers.documents.store', $customer) }}"
                          enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
                        @csrf
                        <div class="flex-1">
                            <label class="bmos-field-label">Archivo (foto de cédula, contrato…)</label>
                            <input type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-600 hover:file:bg-indigo-100">
                        </div>
                        <div>
                            <label class="bmos-field-label">Nombre (opcional)</label>
                            <input type="text" name="name" placeholder="Cédula frontal" class="bmos-input">
                        </div>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Subir</button>
                    </form>
                    @error('file')<p class="px-4 pt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                @endcan

                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead><tr><th>Documento</th><th>Tipo</th><th>Tamaño</th><th>Subido</th><th class="text-right">Acciones</th></tr></thead>
                        <tbody>
                            @forelse ($customer->documents as $doc)
                                <tr>
                                    <td class="font-medium text-slate-800">{{ $doc->name }}</td>
                                    <td>{{ $doc->isImage() ? 'Imagen' : (str_contains($doc->mime, 'pdf') ? 'PDF' : $doc->mime) }}</td>
                                    <td>{{ $doc->humanSize() }}</td>
                                    <td class="text-xs text-slate-500">{{ $doc->created_at?->format('d/m/Y') }}</td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('panel.customers.documents.show', [$customer, $doc]) }}" target="_blank" rel="noopener"
                                               class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600" title="Ver / descargar">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                                            </a>
                                            @can('customers.manage')
                                                <form method="POST" action="{{ route('panel.customers.documents.destroy', [$customer, $doc]) }}"
                                                      onsubmit="return confirm('¿Eliminar «{{ $doc->name }}»?')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600" title="Eliminar">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                                    </button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="bmos-empty">Sin documentos. Sube la cédula u otro documento arriba.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($customer->loans->isNotEmpty())
                <div class="bmos-card overflow-hidden">
                    <div class="border-b border-slate-100 p-4"><p class="font-semibold text-slate-800">Préstamos del cliente</p></div>
                    <div class="overflow-x-auto">
                        <table class="bmos-table">
                            <thead><tr><th>Código</th><th>Total</th><th>Saldo</th><th>Estado</th><th class="text-right">Ver</th></tr></thead>
                            <tbody>
                                @foreach ($customer->loans as $loan)
                                    <tr>
                                        <td class="font-mono text-xs text-slate-500">{{ $loan->code }}</td>
                                        <td>{{ number_format((float) $loan->total, 2) }}</td>
                                        <td class="font-semibold">{{ number_format((float) $loan->balance, 2) }}</td>
                                        <td><span class="bmos-badge {{ $loan->status->badgeClass() }}">{{ $loan->status->label() }}</span></td>
                                        <td class="text-right">
                                            <a href="{{ route('panel.loans.show', $loan) }}" class="text-indigo-600 hover:underline">Ver</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-layouts.admin>
