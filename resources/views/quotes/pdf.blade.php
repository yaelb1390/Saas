{{--
    Cotización en A4, estilo BMIA. Este archivo solo TRADUCE el modelo Quote a la forma común que
    espera documents/layouts/document.blade.php; el maquetado vive ahí y en documents/components/*,
    compartido con la factura, para no mantener dos hojas de estilo iguales por separado.
--}}
@php
    $densidad = \App\Modules\Core\Support\DocumentDensity::paraCantidad($quote->items->count());

    $lineas = $quote->items->map(fn ($item) => [
        'description' => $item->description,
        'quantity' => (string) $item->quantity,
        'unit_price' => (string) $item->unit_price,
        'discount' => (string) $item->discount,
        'subtotal' => (string) $item->subtotal,
    ])->all();
@endphp
@include('documents.layouts.document', [
    'type' => 'quotation',
    'titulo' => 'COTIZACIÓN',
    'codigo' => $quote->code,
    'fecha' => $quote->created_at,
    'vencimiento' => $quote->valid_until,
    'company' => $company,
    'logo' => $logo,
    'vendedor' => $quote->user?->name,
    'clienteNombre' => $quote->customer_name,
    'clienteTelefono' => $quote->customer_phone,
    'clienteRnc' => $quote->customer?->tax_id ?? $quote->customer?->cedula,
    'condicion' => null,
    'moneda' => $company?->currency ?? 'DOP',
    'items' => $lineas,
    'totales' => [
        'subtotal' => (string) $quote->subtotal,
        'tax' => (string) $quote->tax,
        'discount' => (string) $quote->discount_total,
        'total' => (string) $quote->total,
    ],
    'notas' => $quote->notes,
    'densidad' => $densidad,
    'primario' => $company?->documentSetting('primary_color'),
    'secundario' => $company?->documentSetting('secondary_color'),
    'mostrarFirma' => $company?->documentSetting('show_signature'),
    'mostrarDesglose' => $company?->documentSetting('show_tax_breakdown'),
    'mostrarDescuento' => $company?->documentSetting('show_discount'),
    'piePersonalizado' => $company?->documentSetting('footer_text'),
    'condicionesTexto' => $company?->documentSetting('terms_text'),
])
