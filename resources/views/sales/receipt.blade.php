<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recibo {{ $sale->code }} · BM Business OS</title>
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
        .items { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; padding-bottom: 4px; }
        .items td { padding: 3px 0; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .totals .row { padding: 2px 0; }
        .grand { font-size: 15px; font-weight: 800; }
        .badge-ncf { display: inline-block; margin-top: 4px; padding: 2px 8px; border: 1px dashed #6b7280; border-radius: 6px; font-size: 11px; }
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
    <div class="ticket">
        <div class="center">
            <div class="brand">{{ $company?->name ?? 'BM Business OS' }}</div>
            @if ($company?->tax_id)<div class="muted">RNC: {{ $company->tax_id }}</div>@endif
            @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
            @if ($company?->phone)<div class="muted">Tel: {{ $company->phone }}</div>@endif
        </div>

        <hr class="sep">

        <div class="center">
            <strong>RECIBO DE VENTA</strong><br>
            <span class="muted">{{ $sale->code }}</span>
            @if ($invoice)
                <div class="badge-ncf">NCF: {{ $invoice->ncf }}</div>
            @endif
        </div>

        <hr class="sep">

        <div class="row"><span class="muted">Fecha</span><span>{{ ($sale->completed_at ?? $sale->created_at)?->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span class="muted">Cliente</span><span>{{ $sale->customer_name ?? 'Consumidor final' }}</span></div>
        <div class="row"><span class="muted">Pago</span><span>{{ ucfirst($sale->payment_method) }}</span></div>

        <hr class="sep">

        <table class="items">
            <thead>
                <tr><th>Cant.</th><th>Descripción</th><th class="num">Importe</th></tr>
            </thead>
            <tbody>
                @foreach ($sale->items as $item)
                    <tr>
                        <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 3), '0'), '.') }}</td>
                        <td>
                            {{ $item->product?->name ?? 'Producto' }}
                            <div class="muted">{{ number_format((float) $item->unit_price, 2) }} c/u
                                @if ((float) $item->discount > 0) · desc. {{ number_format((float) $item->discount, 2) }}@endif
                            </div>
                            @if ($item->serial)<div class="muted">Serie: {{ $item->serial }}</div>@endif
                            @if ($item->note)<div class="muted">{{ $item->note }}</div>@endif
                            @if ($item->employee)<div class="muted">Atendió: {{ $item->employee->name }}</div>@endif
                        </td>
                        <td class="num">{{ number_format((float) $item->subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div class="totals">
            <div class="row"><span class="muted">Subtotal</span><span>{{ number_format((float) $sale->subtotal, 2) }}</span></div>
            @if ((float) $sale->discount_total > 0)
                <div class="row"><span class="muted">Descuento</span><span>−{{ number_format((float) $sale->discount_total, 2) }}</span></div>
            @endif
            <div class="row"><span class="muted">ITBIS</span><span>{{ number_format((float) $sale->tax, 2) }}</span></div>
            @if ((float) $sale->tip > 0)
                <div class="row"><span class="muted">Propina</span><span>{{ number_format((float) $sale->tip, 2) }}</span></div>
            @endif
            <div class="row grand"><span>TOTAL</span><span>{{ $company?->currency ?? 'DOP' }} {{ number_format((float) $sale->total, 2) }}</span></div>
            <div class="row"><span class="muted">Pagado</span><span>{{ number_format((float) $sale->paid, 2) }}</span></div>
            <div class="row"><span class="muted">Cambio</span><span>{{ number_format((float) $sale->change, 2) }}</span></div>
        </div>

        <hr class="sep">

        <div class="center foot muted">
            @if ($sale->employee)Atendió: {{ $sale->employee->name }}<br>@endif
            ¡Gracias por su compra!<br>
            Generado por BM Business OS
        </div>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <a href="{{ route('panel.sales.receipt.pdf', ['sale' => $sale, 'mode' => 'descargar']) }}" class="btn btn-pdf">⬇️ PDF 80mm</a>
        <a href="{{ route('panel.sales') }}" class="btn btn-back">Volver</a>
    </div>

    <script>
        if (new URLSearchParams(location.search).get('print') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 350));
        }
    </script>
</body>
</html>
