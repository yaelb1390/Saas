<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo de cobro {{ $loan->code }} · BM Business OS</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #e9edf5;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
            color: #1e2230;
            padding: 24px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        .ticket {
            width: 320px;
            background: #fff;
            padding: 22px 20px;
            border-radius: 10px;
            box-shadow: 0 10px 30px -12px rgba(2, 6, 23, 0.35);
            font-size: 12.5px;
            line-height: 1.5;
            color: #111827;
        }
        .center { text-align: center; }
        .muted { color: #6b7280; }
        .brand { font-size: 16px; font-weight: 800; letter-spacing: -0.02em; }
        .sep { border: none; border-top: 1px dashed #cbd2e0; margin: 12px 0; }
        .row { display: flex; justify-content: space-between; gap: 10px; }
        .totals .row { padding: 2px 0; }
        .grand { font-size: 15px; font-weight: 800; }
        .due { font-size: 15px; font-weight: 800; color: #b91c1c; }
        .foot { margin-top: 12px; font-size: 11px; }
        .actions { display: flex; gap: 10px; }
        .btn {
            padding: 10px 18px; border-radius: 10px; border: none; cursor: pointer;
            font-weight: 700; font-size: 13px; text-decoration: none;
        }
        .btn-print { background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; }
        .btn-pdf { background: #fff; color: #4f46e5; border: 1px solid #c7c9f7; }
        .btn-back { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        @media print {
            body { background: #fff; padding: 0; }
            .ticket { box-shadow: none; width: 80mm; border-radius: 0; padding: 4mm; }
            .actions { display: none !important; }
            @page { margin: 4mm; }
        }
    </style>
</head>
<body>
    @php
        $currency = $company?->currency === 'DOP' || $company?->currency === null ? 'RD$' : ($company?->currency.' ');
        $paidToDate = bcsub((string) $loan->total, (string) $payment->balance_after, 2);
    @endphp
    <div class="ticket">
        <div class="center">
            <div class="brand">{{ $company?->name ?? 'BM Business OS' }}</div>
            @if ($company?->tax_id)<div class="muted">RNC: {{ $company->tax_id }}</div>@endif
            @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
            @if ($company?->phone)<div class="muted">Tel: {{ $company->phone }}</div>@endif
        </div>

        <hr class="sep">

        <div class="center">
            <strong>RECIBO DE COBRO</strong><br>
            <span class="muted">Préstamo {{ $loan->code }}</span>
        </div>

        <hr class="sep">

        <div class="row"><span class="muted">Fecha</span><span>{{ $payment->paid_at->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span class="muted">Cliente</span><span>{{ $loan->customer_name ?? $loan->customer?->name ?? '—' }}</span></div>
        <div class="row"><span class="muted">Método</span><span>{{ $payment->method ? ucfirst($payment->method) : 'Efectivo' }}</span></div>
        @if ($payment->note)<div class="row"><span class="muted">Nota</span><span>{{ $payment->note }}</span></div>@endif

        <hr class="sep">

        <div class="totals">
            <div class="row grand"><span>MONTO PAGADO</span><span>{{ $currency }}{{ number_format((float) $payment->amount, 2) }}</span></div>
            <div class="row"><span class="muted">Total del préstamo</span><span>{{ number_format((float) $loan->total, 2) }}</span></div>
            <div class="row"><span class="muted">Total pagado a la fecha</span><span>{{ number_format((float) $paidToDate, 2) }}</span></div>
            <div class="row due"><span>SALDO ADEUDADO</span><span>{{ $currency }}{{ number_format((float) $payment->balance_after, 2) }}</span></div>
        </div>

        <hr class="sep">

        <div class="center foot muted">
            ¡Gracias por su pago!<br>
            Generado por BM Business OS
        </div>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="{{ route('panel.loans.receipt.pdf', ['loan' => $loan, 'payment' => $payment, 'mode' => 'descargar']) }}" class="btn btn-pdf">⬇️ PDF 80mm</a>
        <a href="{{ route('panel.loans.show', $loan) }}" class="btn btn-back">Volver</a>
    </div>

    <script>
        if (new URLSearchParams(location.search).get('print') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 350));
        }
    </script>
</body>
</html>
