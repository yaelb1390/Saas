<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        /*
            Cotización en A4 para dompdf.

            Maquetado con TABLAS y no con flex ni grid: dompdf no los soporta y lo que sale es un
            amontonamiento silencioso —no da error, simplemente queda mal—. La fuente es DejaVu Sans,
            que viene incluida y sí tiene tildes, ñ y el símbolo de pesos.
        */
        * { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 10pt; line-height: 1.45; }

        .cab { width: 100%; border-collapse: collapse; margin-bottom: 14pt; }
        .cab td { vertical-align: top; }
        .logo { max-height: 54pt; max-width: 150pt; }
        .negocio { font-size: 13pt; font-weight: bold; color: #0f172a; }
        .muted { color: #64748b; font-size: 8.5pt; }

        .titulo { text-align: right; }
        .titulo .h { font-size: 17pt; font-weight: bold; color: #4f46e5; letter-spacing: 1pt; }
        .titulo .cod { font-size: 11pt; font-weight: bold; }

        .franja { border-top: 2pt solid #4f46e5; height: 0; font-size: 0; line-height: 0; margin-bottom: 12pt; }

        .datos { width: 100%; border-collapse: collapse; margin-bottom: 12pt; }
        .datos td { width: 50%; vertical-align: top; padding-right: 10pt; }
        .rotulo { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #64748b; }

        .items { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        .items th {
            text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt;
            color: #475569; background: #f1f5f9; padding: 5pt 6pt; border-bottom: 1pt solid #cbd5e1;
        }
        .items td { padding: 5pt 6pt; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .cant { width: 46pt; text-align: right; }
        .items .prec { width: 70pt; }
        .items .imp { width: 76pt; }

        .totales { width: 46%; border-collapse: collapse; margin-top: 10pt; float: right; }
        .totales td { padding: 3pt 6pt; }
        .totales .lbl { color: #475569; }
        .totales .val { text-align: right; white-space: nowrap; }
        .totales .grand td { font-size: 12.5pt; font-weight: bold; border-top: 1pt solid #cbd5e1; padding-top: 5pt; }

        .limpiar { clear: both; height: 0; font-size: 0; line-height: 0; }

        /* La validez va destacada: es lo que distingue una cotización de una lista de precios. */
        .validez {
            margin-top: 16pt; padding: 7pt 10pt; border: 1pt dashed #4f46e5;
            background: #eef2ff; font-size: 9pt;
        }
        .notas { margin-top: 12pt; font-size: 9pt; color: #334155; }
        .pie { margin-top: 20pt; text-align: center; font-size: 8pt; color: #94a3b8; }
    </style>
</head>
<body>
    <table class="cab">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @else
                    <div class="negocio">{{ $company?->name ?? 'Comercio' }}</div>
                @endif

                @if ($logo)
                    <div class="negocio" style="margin-top:4pt">{{ $company?->name ?? 'Comercio' }}</div>
                @endif

                <div class="muted">
                    @if (filled($company?->tax_id))RNC {{ $company->tax_id }}<br>@endif
                    @if (filled($company?->address)){{ $company->address }}<br>@endif
                    @if (filled($company?->phone)){{ $company->phone }}@endif
                </div>
            </td>
            <td class="titulo">
                <div class="h">COTIZACIÓN</div>
                <div class="cod">{{ $quote->code }}</div>
                <div class="muted">{{ $quote->created_at?->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="franja"></div>

    <table class="datos">
        <tr>
            <td>
                <div class="rotulo">Cotizado a</div>
                <div style="font-weight:bold">{{ $quote->customer_name }}</div>
                @if (filled($quote->customer_phone))
                    <div class="muted">{{ $quote->customer_phone }}</div>
                @endif
            </td>
            <td>
                <div class="rotulo">Validez</div>
                <div>
                    @if ($quote->valid_until)
                        Hasta el {{ $quote->valid_until->format('d/m/Y') }}
                    @else
                        Sin fecha de caducidad
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Concepto</th>
                <th class="cant">Cant.</th>
                <th class="num prec">Precio</th>
                <th class="num imp">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    {{-- Sin los ceros de más: «2.000» es la cantidad escrita para una máquina, no
                         para la persona que lee el papel. --}}
                    <td class="cant">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ money((float) $item->unit_price) }}</td>
                    <td class="num">{{ money((float) $item->subtotal) }}</td>
                </tr>
                @if (bccomp((string) $item->discount, '0', 2) > 0)
                    <tr>
                        <td colspan="4" class="muted" style="padding-top:0">
                            Descuento aplicado: −{{ money((float) $item->discount) }}
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr>
            <td class="lbl">Subtotal</td>
            <td class="val">{{ money((float) $quote->subtotal) }}</td>
        </tr>
        <tr>
            <td class="lbl">ITBIS</td>
            <td class="val">{{ money((float) $quote->tax) }}</td>
        </tr>
        @if (bccomp((string) $quote->discount_total, '0', 2) > 0)
            <tr>
                <td class="lbl">Descuento</td>
                <td class="val">−{{ money((float) $quote->discount_total) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Total</td>
            <td class="val">{{ money((float) $quote->total) }}</td>
        </tr>
    </table>

    <div class="limpiar"></div>

    @if ($quote->valid_until)
        <div class="validez">
            <b>Los precios de esta cotización son válidos hasta el {{ $quote->valid_until->format('d/m/Y') }}.</b>
            Pasada esa fecha pueden cambiar.
        </div>
    @endif

    @if (filled($quote->notes))
        <div class="notas">
            <div class="rotulo">Notas</div>
            {!! nl2br(e($quote->notes)) !!}
        </div>
    @endif

    {{-- Se dice en el propio papel que NO es una factura. Un documento con logo, RNC y totales se
         parece lo bastante a una como para que alguien lo presente para gastos y se entere tarde. --}}
    <div class="pie">
        Este documento es una cotización, no un comprobante fiscal.
    </div>
</body>
</html>
