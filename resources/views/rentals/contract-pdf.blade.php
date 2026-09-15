<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        {{-- Mismo maquetado que las cotizaciones (quotes/pdf.blade.php): tablas, no flex/grid —
             dompdf no las soporta—, DejaVu Sans (trae tildes, ñ y el símbolo de pesos), banda de
             color a sangre arriba resuelta quitándole el margen a @page. --}}
        @page { margin: 0; }

        * { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 10pt; line-height: 1.45; }

        .banda { background: #4f46e5; color: #fff; padding: 26pt 34pt 22pt 34pt; }
        .banda table { width: 100%; border-collapse: collapse; }
        .banda td { vertical-align: top; }

        .logo { max-height: 46pt; max-width: 165pt; }
        .marca { font-size: 17pt; font-weight: bold; letter-spacing: 0.5pt; }

        .titulo { text-align: right; }
        .titulo .h { font-size: 19pt; font-weight: bold; letter-spacing: 0.5pt; }
        .titulo .cod { font-size: 13pt; font-weight: bold; letter-spacing: 0.5pt; padding-top: 1pt; }

        .senas { font-size: 8.5pt; line-height: 1.5; padding-top: 12pt; }

        .cuerpo { padding: 24pt 34pt 34pt 34pt; }

        .raya { border-top: 1.6pt solid #4f46e5; margin: 0 0 14pt 0; height: 0; font-size: 0; line-height: 0; }

        .bloques { width: 100%; border-collapse: collapse; }
        .bloques td { width: 50%; vertical-align: top; padding-bottom: 14pt; }
        .rotulo { font-size: 8pt; letter-spacing: 0.4pt; color: #64748b; text-transform: uppercase; }
        .dato { font-size: 9.5pt; padding-top: 2pt; padding-bottom: 5pt; }
        .dato b { font-size: 10pt; }

        .terminos { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        .terminos th {
            background: #4f46e5; color: #fff; text-align: left; font-size: 8.5pt;
            letter-spacing: 0.4pt; padding: 6pt 7pt; border: 0.8pt solid #4f46e5;
        }
        .terminos td { padding: 5pt 7pt; border: 0.8pt solid #1e293b; font-size: 9pt; }
        .terminos .num { text-align: right; white-space: nowrap; }
        .terminos .total td { border: 0.8pt solid #1e293b; }
        .terminos .total .lbl { background: #4f46e5; color: #fff; font-size: 9pt; }
        .terminos .total .val { background: #4f46e5; color: #fff; font-weight: bold; text-align: right; }

        .condiciones { margin-top: 16pt; font-size: 8pt; color: #334155; line-height: 1.6; }
        .condiciones .t { font-size: 8.5pt; font-weight: bold; color: #1e293b; margin-bottom: 4pt; }
        .condiciones ol { margin: 0; padding-left: 14pt; }

        .firmas { width: 100%; border-collapse: collapse; margin-top: 30pt; }
        .firmas td { width: 50%; padding-right: 26pt; vertical-align: bottom; height: 60pt; }
        .firmas img { max-height: 50pt; max-width: 160pt; }
        .linea { border-top: 0.8pt solid #1e293b; padding-top: 3pt; font-size: 8pt; color: #334155; }

        .pie { margin-top: 18pt; font-size: 7.5pt; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="banda">
        <table>
            <tr>
                <td style="width:55%">
                    @if ($logo)
                        <img src="{{ $logo }}" class="logo" alt="">
                    @else
                        <div class="marca">{{ $company?->name ?? 'Comercio' }}</div>
                    @endif
                </td>
                <td class="titulo">
                    <div class="h">CONTRATO DE ALQUILER</div>
                    <div class="cod">{{ $rental->code }}</div>
                </td>
            </tr>
            <tr>
                <td class="senas" colspan="2">
                    @if ($logo)
                        <div style="font-weight:bold; font-size:10pt">{{ $company?->name ?? 'Comercio' }}</div>
                    @endif
                    @if (filled($company?->address)){{ $company->address }}<br>@endif
                    @if (filled($company?->phone)){{ $company->phone }}@endif
                    @if (filled($company?->tax_id)) · RNC {{ $company->tax_id }}@endif
                </td>
            </tr>
        </table>
    </div>

    <div class="cuerpo">
        <div class="raya"></div>

        <table class="bloques">
            <tr>
                <td>
                    <div class="rotulo">Cliente</div>
                    <div class="dato"><b>{{ $rental->customer?->name }}</b></div>
                    @if (filled($rental->customer?->phone))
                        <div class="dato">Tel. {{ $rental->customer->phone }}</div>
                    @endif
                    @if (filled($rental->customer?->cedula))
                        <div class="dato">Cédula {{ $rental->customer->cedula }}</div>
                    @elseif (filled($rental->customer?->tax_id))
                        <div class="dato">RNC {{ $rental->customer->tax_id }}</div>
                    @endif
                </td>
                <td>
                    <div class="rotulo">Vehículo</div>
                    <div class="dato"><b>{{ $rental->vehicle?->nombre() }}</b></div>
                    @if (filled($rental->vehicle?->vin))
                        <div class="dato">Chasis {{ $rental->vehicle->vin }}</div>
                    @endif
                    @if (filled($rental->vehicle?->plate))
                        <div class="dato">Placa {{ $rental->vehicle->plate }}</div>
                    @endif
                    @if (filled($rental->vehicle?->color))
                        <div class="dato">Color {{ $rental->vehicle->color }}</div>
                    @endif
                </td>
            </tr>
            <tr>
                <td>
                    <div class="rotulo">Recogida</div>
                    <div class="dato"><b>{{ $rental->start_at?->format('d/m/Y H:i') }}</b></div>
                </td>
                <td>
                    <div class="rotulo">Devolución pactada</div>
                    <div class="dato"><b>{{ $rental->end_at?->format('d/m/Y H:i') }}</b></div>
                </td>
            </tr>
            @if ($entrega)
                <tr>
                    <td>
                        <div class="rotulo">Kilometraje de entrega</div>
                        <div class="dato">{{ number_format((float) $entrega->mileage) }} km</div>
                    </td>
                    <td>
                        <div class="rotulo">Combustible de entrega</div>
                        <div class="dato">{{ $entrega->fuel_level?->label() }}</div>
                    </td>
                </tr>
            @endif
        </table>

        <table class="terminos">
            <thead>
                <tr><th>CONCEPTO</th><th class="num">MONTO</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Tarifa diaria × {{ $rental->days }} {{ $rental->days === 1 ? 'día' : 'días' }}</td>
                    <td class="num">{{ money((float) $rental->subtotal) }}</td>
                </tr>
                @if ($rental->discount > 0)
                    <tr><td>Descuento</td><td class="num">−{{ money((float) $rental->discount) }}</td></tr>
                @endif
                <tr><td>Depósito de garantía</td><td class="num">{{ money((float) $rental->deposit_amount) }}</td></tr>
                <tr class="total">
                    <td class="lbl">Total del alquiler</td>
                    <td class="val">{{ money((float) $rental->total) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="condiciones">
            <div class="t">CONDICIONES</div>
            <ol>
                <li>El vehículo se entrega y se devuelve en las fechas y horas indicadas arriba, salvo acuerdo distinto por escrito.</li>
                @if ($rental->vehicle?->rental_km_limit_daily)
                    <li>
                        El límite de kilometraje es de {{ number_format((float) $rental->vehicle->rental_km_limit_daily) }} km por día.
                        Cada kilómetro de más se cobra a {{ money((float) ($rental->vehicle->extra_km_price ?? 0)) }}.
                    </li>
                @endif
                <li>El depósito de garantía se devuelve al cierre del alquiler, descontados los cargos por daños, combustible o kilometraje que correspondan.</li>
                <li>El arrendatario es responsable del vehículo durante todo el período de alquiler, incluyendo infracciones de tránsito y daños no cubiertos por el seguro.</li>
                <li>El vehículo debe devolverse con el mismo nivel de combustible con el que se entregó.</li>
            </ol>
        </div>

        <table class="firmas">
            <tr>
                <td>
                    @if ($firma)
                        <img src="{{ $firma }}" alt="">
                    @endif
                </td>
                <td></td>
            </tr>
            <tr>
                <td><div class="linea">Firma del Cliente</div></td>
                <td><div class="linea">Firma de {{ $company?->name ?? 'la Empresa' }}</div></td>
            </tr>
        </table>

        <div class="pie">
            Contrato generado el {{ now()->format('d/m/Y H:i') }}. Documento interno de la empresa, no es un comprobante fiscal.
        </div>
    </div>
</body>
</html>
