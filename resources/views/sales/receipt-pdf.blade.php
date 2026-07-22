<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        /* Recibo térmico 80mm para dompdf. El tamaño de página (80mm de ancho) se define en el
           controlador; aquí solo maquetamos con tablas, que es lo que dompdf renderiza de forma fiable
           (sin flexbox/grid). Fuente base DejaVu Sans (incluida) para tildes, ñ, ¡ y símbolos. */
        * { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #000; font-size: 9pt; line-height: 1.35; }
        .center { text-align: center; }
        .muted { color: #444; }
        .brand { font-size: 12pt; font-weight: bold; }
        .sep { border-top: 1px dashed #000; height: 0; margin: 5pt 0; font-size: 0; line-height: 0; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 1pt 0; vertical-align: top; }
        .meta .lbl { color: #444; }
        .meta .val { text-align: right; }
        .items th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #444;
            border-bottom: 1px solid #000; padding-bottom: 2pt; }
        .items td { padding: 2pt 0; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .qty { width: 34pt; }
        .items .amt { width: 54pt; }
        .totals td { padding: 1pt 0; }
        .totals .lbl { color: #444; }
        .totals .val { text-align: right; }
        .grand td { font-size: 11pt; font-weight: bold; padding-top: 3pt; }
        .ncf { display: inline-block; margin-top: 3pt; padding: 2pt 6pt; border: 1px dashed #000; font-size: 8pt; }
        .foot { margin-top: 6pt; font-size: 8pt; }
    </style>
</head>
<body>
    <div class="center">
        <div class="brand">{{ $company?->name ?? 'BM Business OS' }}</div>
        @if ($company?->tax_id)<div class="muted">RNC: {{ $company->tax_id }}</div>@endif
        @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
        @if ($company?->phone)<div class="muted">Tel: {{ $company->phone }}</div>@endif
    </div>

    <div class="sep"></div>

    <div class="center">
        <strong>RECIBO DE VENTA</strong><br>
        <span class="muted">{{ $sale->code }}</span>
        @if ($invoice)
            <div><span class="ncf">NCF: {{ $invoice->ncf }}</span></div>
        @endif
    </div>

    <div class="sep"></div>

    <table class="meta">
        <tr><td class="lbl">Fecha</td><td class="val">{{ ($sale->completed_at ?? $sale->created_at)?->format('d/m/Y H:i') }}</td></tr>
        <tr><td class="lbl">Cliente</td><td class="val">{{ $sale->customer_name ?? 'Consumidor final' }}</td></tr>
        <tr><td class="lbl">Pago</td><td class="val">{{ ucfirst($sale->payment_method) }}</td></tr>
    </table>

    <div class="sep"></div>

    <table class="items">
        <thead>
            <tr><th class="qty">Cant.</th><th>Descripción</th><th class="amt num">Importe</th></tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td class="qty">{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                    <td>
                        {{ $item->product?->name ?? 'Producto' }}
                        <div class="muted">{{ number_format((float) $item->unit_price, 2) }} c/u @if ((float) $item->discount > 0)· desc. {{ number_format((float) $item->discount, 2) }}@endif</div>
                        @if ($item->serial)<div class="muted">Serie: {{ $item->serial }}</div>@endif
                        @if ($item->note)<div class="muted">{{ $item->note }}</div>@endif
                        @if ($item->employee)<div class="muted">Atendió: {{ $item->employee->name }}</div>@endif
                    </td>
                    <td class="amt num">{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="sep"></div>

    <table class="totals">
        <tr><td class="lbl">Subtotal</td><td class="val">{{ number_format((float) $sale->subtotal, 2) }}</td></tr>
        @if ((float) $sale->discount_total > 0)
            <tr><td class="lbl">Descuento</td><td class="val">−{{ number_format((float) $sale->discount_total, 2) }}</td></tr>
        @endif
        <tr><td class="lbl">ITBIS</td><td class="val">{{ number_format((float) $sale->tax, 2) }}</td></tr>
        @if ((float) $sale->tip > 0)
            <tr><td class="lbl">Propina</td><td class="val">{{ number_format((float) $sale->tip, 2) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td class="val">{{ $company?->currency ?? 'DOP' }} {{ number_format((float) $sale->total, 2) }}</td></tr>
        <tr><td class="lbl">Pagado</td><td class="val">{{ number_format((float) $sale->paid, 2) }}</td></tr>
        <tr><td class="lbl">Cambio</td><td class="val">{{ number_format((float) $sale->change, 2) }}</td></tr>
    </table>

    <div class="sep"></div>

    <div class="center foot muted">
        @if ($sale->employee)Atendió: {{ $sale->employee->name }}<br>@endif
        ¡Gracias por su compra!<br>
        Generado por BM Business OS
    </div>
</body>
</html>
