{{--
    Factura fiscal (comprobante ya emitido con NCF) en A4, estilo BMIA. Traduce Invoice a la forma
    común de documents/layouts/document.blade.php, igual que quotes/pdf.blade.php hace con Quote.
--}}
@php
    $densidad = \App\Modules\Core\Support\DocumentDensity::paraCantidad($invoice->items->count());

    $lineas = $invoice->items->map(fn ($item) => [
        'description' => $item->description,
        'quantity' => (string) $item->quantity,
        'unit_price' => (string) $item->unit_price,
        'discount' => '0',
        'subtotal' => (string) $item->subtotal,
    ])->all();

    $formaPago = \App\Modules\Sales\Enums\PaymentMethod::tryFrom((string) $invoice->sale?->payment_method)?->label();
@endphp
@include('documents.layouts.document', [
    'type' => 'invoice',
    'titulo' => 'FACTURA',
    'codigo' => $invoice->numeroInterno(),
    'ncf' => $invoice->ncf,
    // Con e-CF, el tipo en palabras es el del comprobante electrónico [IT §18].
    // Con el e-CF de prueba (modo «En paralelo») el comprobante sigue siendo la factura B.
    'ncfLabel' => empty($timbre['prueba']) ? ($timbre['tipo'] ?? $invoice->type->label()) : $invoice->type->label(),
    'timbre' => $timbre ?? null,
    'fecha' => $invoice->issued_at,
    'company' => $company,
    'logo' => $logo,
    'vendedor' => $invoice->user?->name,
    'clienteNombre' => $invoice->customer_name ?? 'Consumidor final',
    'clienteTelefono' => $invoice->customer?->phone,
    'clienteRnc' => $invoice->customer_tax_id,
    'condicion' => $formaPago,
    'moneda' => $company?->currency ?? 'DOP',
    'items' => $lineas,
    'totales' => [
        'subtotal' => (string) $invoice->subtotal,
        'tax' => (string) $invoice->tax,
        'discount' => '0',
        'total' => (string) $invoice->total,
    ],
    'notas' => null,
    'densidad' => $densidad,
    'primario' => $company?->documentSetting('primary_color'),
    'secundario' => $company?->documentSetting('secondary_color'),
    'mostrarFirma' => $company?->documentSetting('show_signature'),
    'mostrarDesglose' => $company?->documentSetting('show_tax_breakdown'),
    'mostrarDescuento' => false,
    'piePersonalizado' => $company?->documentSetting('footer_text'),
    'condicionesTexto' => $company?->documentSetting('terms_text'),
])
