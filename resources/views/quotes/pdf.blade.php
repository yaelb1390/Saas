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

            La cabecera es una BANDA DE COLOR a sangre por arriba y los lados. En dompdf eso no se
            consigue con `position:absolute` ni con márgenes negativos: se le quita el margen a la
            página (@page) y luego se le devuelve al contenido, que es lo que hace `.cuerpo`.
        */
        @page { margin: 0; }

        * { margin: 0; padding: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1e293b; font-size: 10pt; line-height: 1.45; }

        /* ------------------------------------------------------------------ La banda azul */
        .banda { background: #14538c; color: #fff; padding: 26pt 34pt 22pt 34pt; }
        .banda table { width: 100%; border-collapse: collapse; }
        .banda td { vertical-align: top; }

        .logo { max-height: 46pt; max-width: 165pt; }
        /* Sin logo va el nombre, y grande: el papel tiene que decir de quién es aunque no haya imagen. */
        .marca { font-size: 17pt; font-weight: bold; letter-spacing: 0.5pt; }

        /*
            El título va en DOS LÍNEAS a propósito: «COTIZACIÓN» arriba y el código debajo.
            En el modelo de referencia cabía en una porque el número era «#123»; aquí los códigos son
            «COT-000002» y en una sola línea partía por la mitad —«COTIZACIÓN COT-» / «000002»—, que
            es peor que cualquier maquetación. Separados, da igual lo que crezca el correlativo.
        */
        .titulo { text-align: right; }
        .titulo .h { font-size: 21pt; font-weight: bold; letter-spacing: 0.5pt; }
        .titulo .cod { font-size: 13pt; font-weight: bold; letter-spacing: 0.5pt; padding-top: 1pt; }

        .senas { font-size: 8.5pt; line-height: 1.5; padding-top: 12pt; }

        /* Los tres datos de cabecera, en dos columnas para que los valores queden alineados. */
        .meta { padding-top: 12pt; }
        .meta table { width: 100%; }
        .meta .k {
            font-size: 8pt; letter-spacing: 0.4pt; text-transform: uppercase;
            width: 62pt; padding-bottom: 2pt; vertical-align: top;
        }
        .meta .v { font-size: 8.5pt; text-transform: uppercase; padding-bottom: 2pt; vertical-align: top; }

        /* ------------------------------------------------------------------ El cuerpo */
        .cuerpo { padding: 0 34pt 34pt 34pt; }

        .raya { border-top: 1.6pt solid #14538c; margin: 20pt 0 14pt 0; height: 0; font-size: 0; line-height: 0; }

        .rotulo { font-size: 9pt; letter-spacing: 0.3pt; color: #334155; }
        .proyecto { min-height: 46pt; font-size: 9.5pt; color: #334155; padding-top: 5pt; }

        /* ------------------------------------------------------------------ La tabla */
        .items { width: 100%; border-collapse: collapse; margin-top: 16pt; }
        .items th {
            background: #14538c; color: #fff; text-align: left; font-size: 8.5pt;
            letter-spacing: 0.4pt; padding: 6pt 7pt; border: 0.8pt solid #14538c;
        }
        .items td { padding: 5pt 7pt; border: 0.8pt solid #1e293b; font-size: 9pt; vertical-align: top; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .cant { width: 68pt; }
        .items .prec { width: 78pt; }
        .items .imp { width: 84pt; }

        /* La fila del total: la etiqueta y la cifra en azul, como el resto del bloque. */
        .items .total td { border: 0.8pt solid #1e293b; }
        .items .total .lbl { background: #14538c; color: #fff; font-size: 9pt; }
        .items .total .val { background: #14538c; color: #fff; font-weight: bold; text-align: right; }
        .items .vacia td { height: 13pt; }

        .validez { margin-top: 9pt; font-size: 8.5pt; font-weight: bold; }
        .notas { margin-top: 14pt; font-size: 9pt; color: #334155; }

        /* ------------------------------------------------------------------ Las firmas */
        .firmas { width: 100%; border-collapse: collapse; margin-top: 46pt; }
        .firmas td { width: 50%; padding-right: 26pt; vertical-align: bottom; }
        .linea { border-top: 0.8pt solid #1e293b; padding-top: 3pt; font-size: 8pt; color: #334155; }

        .pie { margin-top: 22pt; font-size: 7.5pt; color: #94a3b8; }
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
                        {{-- Sin logo NO se deja un hueco: el nombre del negocio ocupa su sitio y el
                             papel sigue diciendo de quién viene. Un espacio en blanco arriba a la
                             izquierda parece un error de impresión. --}}
                        <div class="marca">{{ $company?->name ?? 'Comercio' }}</div>
                    @endif
                </td>
                <td class="titulo">
                    <div class="h">COTIZACIÓN</div>
                    <div class="cod">{{ $quote->code }}</div>
                </td>
            </tr>
            <tr>
                <td class="senas">
                    {{-- Con logo, el nombre va aquí debajo: el logo puede ser solo un símbolo. --}}
                    @if ($logo)
                        <div style="font-weight:bold; font-size:10pt">{{ $company?->name ?? 'Comercio' }}</div>
                    @endif
                    @if (filled($company?->address)){{ $company->address }}<br>@endif
                    @if (filled($company?->phone)){{ $company->phone }}<br>@endif
                    @if (filled($company?->tax_id))RNC {{ $company->tax_id }}@endif
                </td>
                <td class="meta">
                    <table>
                        <tr>
                            <td class="k">Fecha:</td>
                            <td class="v">{{ $quote->created_at?->format('d/m/Y') }}</td>
                        </tr>
                        @if (filled($quote->user?->name))
                            <tr>
                                <td class="k">Vendedor:</td>
                                <td class="v">{{ $quote->user->name }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="k">Cliente:</td>
                            <td class="v">{{ $quote->customer_name }}</td>
                        </tr>
                        @if (filled($quote->customer_phone))
                            <tr>
                                <td class="k">Teléfono:</td>
                                <td class="v">{{ $quote->customer_phone }}</td>
                            </tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="cuerpo">
        <div class="raya"></div>

        <div class="rotulo">DESCRIPCIÓN DEL PROYECTO:</div>
        <div class="proyecto">
            {{-- Las notas van AQUÍ y no al final: es donde se explica qué se está cotizando, que es
                 lo primero que lee quien recibe el papel. Si no hay nada escrito, el hueco se queda
                 en blanco a propósito, para poder rellenarlo a mano sobre el impreso. --}}
            @if (filled($quote->notes))
                {!! nl2br(e($quote->notes)) !!}
            @endif
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th>PRODUCTO</th>
                    <th class="cant">CANTIDAD</th>
                    <th class="prec">PRECIO</th>
                    <th class="imp">TOTAL</th>
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
                            <td colspan="4" style="font-size:8pt; color:#64748b">
                                Descuento aplicado: −{{ money((float) $item->discount) }}
                            </td>
                        </tr>
                    @endif
                @endforeach

                {{-- Filas en blanco hasta llenar el cuadro, como en el impreso de toda la vida. Una
                     tabla de dos líneas flotando sobre media página en blanco parece un documento a
                     medio hacer; el recuadro cerrado se ve terminado. Con muchas líneas no se añade
                     ninguna, y dompdf pagina solo. --}}
                @for ($i = count($quote->items); $i < 6; $i++)
                    <tr class="vacia">
                        <td></td><td></td><td></td><td></td>
                    </tr>
                @endfor

                @if (bccomp((string) $quote->tax, '0', 2) > 0)
                    <tr>
                        <td colspan="2" style="border:0"></td>
                        <td class="num">Subtotal</td>
                        <td class="num">{{ money((float) $quote->subtotal) }}</td>
                    </tr>
                    <tr>
                        <td colspan="2" style="border:0"></td>
                        <td class="num">ITBIS</td>
                        <td class="num">{{ money((float) $quote->tax) }}</td>
                    </tr>
                @endif

                @if (bccomp((string) $quote->discount_total, '0', 2) > 0)
                    <tr>
                        <td colspan="2" style="border:0"></td>
                        <td class="num">Descuento</td>
                        <td class="num">−{{ money((float) $quote->discount_total) }}</td>
                    </tr>
                @endif

                <tr class="total">
                    <td colspan="2" style="border:0"></td>
                    <td class="lbl">Total</td>
                    <td class="val">{{ money((float) $quote->total) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- La validez es lo que distingue una cotización de una lista de precios: sin fecha, un
             cliente puede volver dentro de seis meses con este papel en la mano. --}}
        <div class="validez">
            @if ($quote->valid_until)
                Cotización válida hasta el {{ $quote->valid_until->format('d/m/Y') }}.
            @else
                Cotización sin fecha de caducidad.
            @endif
        </div>

        <table class="firmas">
            <tr>
                <td><div class="linea">Firma de Cliente</div></td>
                <td><div class="linea">Firma de {{ filled($quote->user?->name) ? 'Vendedor' : 'la Empresa' }}</div></td>
            </tr>
        </table>

        {{-- Se dice en el propio papel que NO es una factura. Un documento con logo, RNC y totales se
             parece lo bastante a una como para que alguien lo presente para gastos y se entere tarde. --}}
        <div class="pie">
            Este documento es una cotización, no un comprobante fiscal.
        </div>
    </div>
</body>
</html>
