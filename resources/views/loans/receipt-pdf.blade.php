<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        /* Recibo de cobro térmico 80mm para dompdf. El ancho de página se define en el controlador;
           aquí solo se maqueta con tablas (dompdf no renderiza flexbox/grid de forma fiable). Fuente
           DejaVu Sans (incluida) para tildes, ñ, ¡ y símbolos. */
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
        .totals td { padding: 1pt 0; }
        .totals .lbl { color: #444; }
        .totals .val { text-align: right; }
        .grand td { font-size: 11pt; font-weight: bold; padding-top: 3pt; }
        .due td { font-size: 11pt; font-weight: bold; padding-top: 3pt; }
        .foot { margin-top: 6pt; font-size: 8pt; }
    </style>
</head>
<body>
    @php
        $currency = $company?->currency === 'DOP' || $company?->currency === null ? 'RD$' : ($company?->currency.' ');
        $paidToDate = bcsub((string) $loan->total, (string) $payment->balance_after, 2);
    @endphp
    <div class="center">
        <div class="brand">{{ $company?->name ?? 'BM Business OS' }}</div>
        @if ($company?->tax_id)<div class="muted">RNC: {{ $company->tax_id }}</div>@endif
        @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
        @if ($company?->phone)<div class="muted">Tel: {{ $company->phone }}</div>@endif
    </div>

    <div class="sep"></div>

    <div class="center">
        <strong>RECIBO DE COBRO</strong><br>
        <span class="muted">Préstamo {{ $loan->code }}</span>
    </div>

    <div class="sep"></div>

    <table class="meta">
        <tr><td class="lbl">Fecha</td><td class="val">{{ $payment->paid_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td class="lbl">Cliente</td><td class="val">{{ $loan->customer_name ?? $loan->customer?->name ?? '—' }}</td></tr>
        <tr><td class="lbl">Método</td><td class="val">{{ $payment->method ? ucfirst($payment->method) : 'Efectivo' }}</td></tr>
        @if ($payment->note)<tr><td class="lbl">Nota</td><td class="val">{{ $payment->note }}</td></tr>@endif
    </table>

    <div class="sep"></div>

    <table class="totals">
        <tr class="grand"><td>MONTO PAGADO</td><td class="val">{{ $currency }}{{ number_format((float) $payment->amount, 2) }}</td></tr>
        <tr><td class="lbl">Total del préstamo</td><td class="val">{{ number_format((float) $loan->total, 2) }}</td></tr>
        <tr><td class="lbl">Total pagado a la fecha</td><td class="val">{{ number_format((float) $paidToDate, 2) }}</td></tr>
        <tr class="due"><td>SALDO ADEUDADO</td><td class="val">{{ $currency }}{{ number_format((float) $payment->balance_after, 2) }}</td></tr>
    </table>

    <div class="sep"></div>

    <div class="center foot muted">
        ¡Gracias por su pago!<br>
        Generado por BM Business OS
    </div>
</body>
</html>
