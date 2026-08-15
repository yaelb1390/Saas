@php
    use App\Modules\Billing\Enums\GoodsServicesType;

    $paymentMethods = [
        'cash' => 'Efectivo', 'transfer' => 'Transferencia', 'check' => 'Cheque', 'card' => 'Tarjeta',
        'credit' => 'Crédito', 'swap' => 'Permuta', 'credit_note' => 'Nota de crédito', 'other' => 'Otras',
    ];
    $mesLabel = \Illuminate\Support\Carbon::parse($period.'-01')->locale('es')->isoFormat('MMMM [de] YYYY');
@endphp

<x-layouts.admin title="Compras (606)" heading="Compras (606)"
                 subheading="Sube tus facturas de compra y descarga el envío para la DGII">

    {{-- Barra: mes + exportaciones --}}
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label class="bmos-field-label">Mes</label>
                <input type="month" name="period" value="{{ $period }}" class="bmos-input"
                       onchange="this.form.submit()">
            </div>
        </form>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('panel.purchase-invoices.606', ['period' => $period]) }}" class="bmos-btn bmos-btn-ghost">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5"/></svg>
                Descargar 606 (TXT)
            </a>
            <a href="{{ route('panel.purchase-invoices.excel', ['period' => $period]) }}" class="bmos-btn bmos-btn-ghost">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                Excel
            </a>
        </div>
    </div>

    {{-- Resumen del mes --}}
    <div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
        <div class="bmos-stat">
            <p class="bmos-stat-label">Facturas · {{ ucfirst($mesLabel) }}</p>
            <p class="bmos-stat-value">{{ $invoices->count() }}</p>
        </div>
        <div class="bmos-stat">
            <p class="bmos-stat-label">Monto facturado</p>
            <p class="bmos-stat-value">{{ money($totalAmount) }}</p>
        </div>
        <div class="bmos-stat">
            <p class="bmos-stat-label">ITBIS</p>
            <p class="bmos-stat-value">{{ money($totalItbis) }}</p>
        </div>
    </div>

    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <p class="font-semibold text-slate-800">Facturas de compra</p>
            @can('purchase_invoices.manage')
                <x-panel.create-modal title="Nueva factura de compra" label="Subir factura"
                                       form="purchase_invoice_create" width="max-w-3xl"
                                       enctype="multipart/form-data" :action="route('panel.purchase-invoices.store')">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {{-- Archivo --}}
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="bmos-field-label">Foto o PDF de la factura</label>
                            <input type="file" name="file" accept="image/*,.pdf" required
                                   class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-600 hover:file:bg-indigo-100">
                            <p class="mt-1 text-xs text-slate-400">Imagen (JPG/PNG/WEBP) o PDF, hasta 8 MB. Luego escribe los datos abajo.</p>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="bmos-field-label">Proveedor</label>
                            <input type="text" name="provider_name" value="{{ old('provider_name') }}"
                                   placeholder="Nombre del proveedor" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">Forma de pago</label>
                            <select name="payment_method" class="bmos-input" required>
                                @foreach ($paymentMethods as $val => $label)
                                    <option value="{{ $val }}" @selected(old('payment_method', 'cash') === $val)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="bmos-field-label">RNC / Cédula</label>
                            <input type="text" name="provider_tax_id" value="{{ old('provider_tax_id') }}"
                                   placeholder="Ej: 101xxxxxx" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">Tipo de ID</label>
                            <select name="provider_tax_id_kind" class="bmos-input" required>
                                @foreach ($taxIdKinds as $kind)
                                    <option value="{{ $kind->value }}" @selected(old('provider_tax_id_kind', '1') === $kind->value)>{{ $kind->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="bmos-field-label">Tipo de bien/servicio</label>
                            <select name="goods_services_type" class="bmos-input" required>
                                @foreach ($goodsServicesTypes as $type)
                                    <option value="{{ $type->value }}" @selected(old('goods_services_type', GoodsServicesType::CostoDeVenta->value) === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="bmos-field-label">NCF</label>
                            <input type="text" name="ncf" value="{{ old('ncf') }}" placeholder="B01..." class="bmos-input" required>
                        </div>
                        <div>
                            <label class="bmos-field-label">NCF modificado (opcional)</label>
                            <input type="text" name="ncf_modified" value="{{ old('ncf_modified') }}" class="bmos-input">
                        </div>
                        <div></div>

                        <div>
                            <label class="bmos-field-label">Fecha del comprobante</label>
                            <input type="date" name="invoice_date" value="{{ old('invoice_date', now()->toDateString()) }}" class="bmos-input" required>
                        </div>
                        <div>
                            <label class="bmos-field-label">Fecha de pago (opcional)</label>
                            <input type="date" name="payment_date" value="{{ old('payment_date') }}" class="bmos-input">
                        </div>
                        <div></div>

                        <div>
                            <label class="bmos-field-label">Monto facturado (sin ITBIS)</label>
                            <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="bmos-input" required>
                        </div>
                        <div>
                            <label class="bmos-field-label">ITBIS facturado</label>
                            <input type="number" step="0.01" min="0" name="itbis" value="{{ old('itbis', 0) }}" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">ITBIS retenido</label>
                            <input type="number" step="0.01" min="0" name="itbis_retenido" value="{{ old('itbis_retenido', 0) }}" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">Retención renta (ISR)</label>
                            <input type="number" step="0.01" min="0" name="isr_retenido" value="{{ old('isr_retenido', 0) }}" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">ISC (opcional)</label>
                            <input type="number" step="0.01" min="0" name="isc" value="{{ old('isc', 0) }}" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">Otros impuestos (opcional)</label>
                            <input type="number" step="0.01" min="0" name="other_taxes" value="{{ old('other_taxes', 0) }}" class="bmos-input">
                        </div>
                        <input type="hidden" name="tip" value="0">
                    </div>
                </x-panel.create-modal>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead>
                    <tr>
                        <th>Proveedor</th><th>RNC/Cédula</th><th>NCF</th><th>Tipo</th><th>Fecha</th>
                        <th class="text-right">Monto</th><th class="text-right">ITBIS</th><th>Adjunto</th>
                        <th class="text-right">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr>
                            <td class="font-medium text-slate-800">{{ $inv->provider_name ?? '—' }}</td>
                            <td class="text-slate-500">{{ $inv->provider_tax_id ?? '—' }}</td>
                            <td class="font-mono text-xs text-slate-500">{{ $inv->ncf }}</td>
                            <td class="text-xs text-slate-500">{{ $inv->goods_services_type->value }}</td>
                            <td>{{ $inv->invoice_date->format('d/m/Y') }}</td>
                            <td class="text-right">{{ number_format((float) $inv->amount, 2) }}</td>
                            <td class="text-right">{{ number_format((float) $inv->itbis, 2) }}</td>
                            <td>
                                @if ($inv->hasFile())
                                    <a href="{{ route('panel.purchase-invoices.file', $inv) }}" target="_blank"
                                       class="text-indigo-600 hover:underline">{{ $inv->isImage() ? 'Ver foto' : 'Ver PDF' }}</a>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="text-right">
                                @can('purchase_invoices.manage')
                                    <x-panel.confirm-action
                                        :action="route('panel.purchase-invoices.destroy', $inv)"
                                        title="¿Eliminar la factura {{ $inv->ncf }}?"
                                        message="Se archiva y deja de contar en el ITBIS adelantado del período."
                                        note="Si ya la reportaste a la DGII, el reporte enviado no cambia: solo cambian los próximos."
                                        tooltip="Eliminar"
                                        class="text-rose-500 hover:text-rose-700">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.35 9m-4.78 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                    </x-panel.confirm-action>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="bmos-empty">Sin facturas este mes. Sube la primera con «Subir factura».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.admin>
