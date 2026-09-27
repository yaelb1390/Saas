{{--
    Documento comercial compartido (cotización / factura), estilo BMIA.

    Maquetado con tablas y no con flex/grid: dompdf no los soporta y el resultado es un
    amontonamiento silencioso. Los radios sí funcionan en dompdf cuando se aplican sobre un <div>
    (una "tarjeta"), así que las tarjetas son divs y solo la grilla de líneas y los totales usan
    <table> — ahí SÍ hace falta, para que el encabezado (<thead>) se repita solo en cada página
    nueva cuando el documento no cabe en una sola hoja.

    Variables que recibe esta plantilla (las arman quotes/pdf.blade.php e invoices/pdf.blade.php,
    cada una traduciendo su propio modelo a esta forma común, para que este archivo no conozca
    Quote ni Invoice):

    string   $type            'quotation' | 'invoice'
    string   $titulo           «COTIZACIÓN» o «FACTURA»
    string   $codigo           Número a mostrar bajo el título
    ?string  $ncf              Solo factura
    ?string  $ncfLabel         Tipo de comprobante, solo factura
    \Illuminate\Support\Carbon $fecha
    ?\Illuminate\Support\Carbon $vencimiento   Solo cotización
    $company, ?string $logo
    string   $clienteNombre
    ?string  $clienteTelefono
    ?string  $clienteRnc
    ?string  $condicion        Forma de pago, si se conoce
    string   $moneda
    array<int, array{description:string, quantity:string, unit_price:string, discount:string, subtotal:string}> $items
    array{subtotal:string, tax:string, discount:string, total:string} $totales
    ?string  $notas
    ?string  $vendedor
    string   $densidad         DocumentDensity::NORMAL|COMPACT|ULTRA
    string   $primario         Color primario, «#1677ff»
    string   $secundario       Color secundario, «#0b3d91»
    bool     $mostrarFirma
    bool     $mostrarDesglose
    bool     $mostrarDescuento
    ?string  $piePersonalizado
    ?string  $condicionesTexto
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #1e293b;
            font-size: 9.5pt;
            line-height: 1.45;
        }

        /* La densidad SOLO toca tipografía/espaciado: nunca oculta ni recorta líneas. */
        body[data-densidad='compact'] { font-size: 8.7pt; }
        body[data-densidad='compact'] .items td, body[data-densidad='compact'] .items th { padding: 4.2pt 6pt; }
        body[data-densidad='ultra'] { font-size: 8pt; }
        body[data-densidad='ultra'] .items td, body[data-densidad='ultra'] .items th { padding: 3pt 5pt; }
        body[data-densidad='ultra'] .cuerpo { padding-top: 14pt; }

        .pagina { padding: 28pt 30pt 26pt 30pt; }

        /* ------------------------------------------------------------- Tarjetas (radio BMIA) */
        .tarjeta {
            border: 1pt solid #e2e8f0;
            border-radius: 12pt;
            background: #fff;
        }
        .tarjeta-gris { background: #f8fafc; }

        /* ------------------------------------------------------------- Cabecera */
        .cab-tabla { width: 100%; border-collapse: collapse; }
        .cab-tabla td { vertical-align: top; }
        .logo { max-height: 64pt; max-width: 200pt; }
        .marca { font-size: 15pt; font-weight: bold; color: {{ $secundario }}; }
        .comercial { font-size: 9.5pt; font-weight: bold; color: #334155; margin-top: 5pt; }
        .senas { font-size: 8pt; color: #64748b; line-height: 1.5; margin-top: 6pt; }

        .doc-titulo { text-align: right; }
        .doc-titulo .h {
            font-size: 19pt; font-weight: bold; letter-spacing: 0.4pt; color: {{ $primario }};
        }
        .doc-titulo .cod { font-size: 11pt; font-weight: bold; color: #334155; margin-top: 2pt; }
        .doc-meta { font-size: 8.3pt; color: #64748b; margin-top: 6pt; }
        .doc-meta .fila { margin-top: 1.5pt; }
        .doc-meta .k { color: #94a3b8; }

        hr.raya { border: none; border-top: 1.4pt solid {{ $primario }}; margin: 14pt 0; }

        /* ------------------------------------------------------------- Cliente / info fiscal */
        .dos-cols { width: 100%; border-collapse: separate; border-spacing: 8pt 0; margin-left: -8pt; }
        .dos-cols td { width: 50%; vertical-align: top; }
        .tarjeta-pad { padding: 9pt 11pt; }
        .card-rotulo {
            font-size: 7.3pt; font-weight: bold; letter-spacing: 0.5pt; text-transform: uppercase;
            color: {{ $primario }}; margin-bottom: 5pt;
        }
        .card-linea { font-size: 8.7pt; color: #334155; margin-top: 2pt; }
        .card-linea b { color: #0f172a; }

        /* ------------------------------------------------------------- Tabla de líneas */
        .items { width: 100%; border-collapse: collapse; margin-top: 14pt; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th {
            background: {{ $secundario }}; color: #fff; text-align: left; font-size: 7.8pt;
            font-weight: bold; letter-spacing: 0.3pt; padding: 6pt 7pt;
        }
        .items th.num, .items td.num { text-align: right; }
        .items td { padding: 5.5pt 7pt; border-bottom: 0.8pt solid #eef1f6; font-size: 8.8pt; vertical-align: top; }
        .items tbody tr:nth-child(even) { background: #f8fafc; }
        .items .descuento-linea { font-size: 7.6pt; color: #94a3b8; }

        /* ------------------------------------------------------------- Totales */
        .totales { width: 220pt; margin-left: auto; margin-top: 10pt; border-collapse: collapse; }
        .totales td { padding: 3.5pt 4pt; font-size: 8.7pt; }
        .totales .lbl { color: #64748b; }
        .totales .val { text-align: right; }
        .totales .final td { border-top: 1.2pt solid {{ $primario }}; padding-top: 6pt; }
        .totales .final .lbl { font-size: 10pt; font-weight: bold; color: #0f172a; }
        .totales .final .val { font-size: 12pt; font-weight: bold; color: {{ $primario }}; }

        /* ------------------------------------------------------------- Notas / condiciones */
        .bloque-texto { margin-top: 12pt; font-size: 8.3pt; color: #475569; }
        .bloque-texto .rot { font-weight: bold; color: #334155; margin-bottom: 2pt; }

        /* ------------------------------------------------------------- Firmas */
        .firmas { width: 100%; border-collapse: collapse; margin-top: 34pt; }
        .firmas td { width: 50%; padding-right: 24pt; vertical-align: bottom; }
        .firma-linea { border-top: 0.8pt solid #94a3b8; padding-top: 3pt; font-size: 7.6pt; color: #64748b; }

        /* ------------------------------------------------------------- Pie */
        .pie { margin-top: 20pt; font-size: 7.2pt; color: #94a3b8; text-align: center; }
    </style>
</head>
<body data-densidad="{{ $densidad }}">
    <div class="pagina">
        @include('documents.components.header', [
            'type' => $type, 'titulo' => $titulo, 'codigo' => $codigo, 'ncf' => $ncf ?? null,
            'fecha' => $fecha, 'vencimiento' => $vencimiento ?? null, 'company' => $company,
            'logo' => $logo, 'vendedor' => $vendedor ?? null,
        ])

        <hr class="raya">

        <div class="cuerpo">
            @include('documents.components.customer', [
                'type' => $type, 'clienteNombre' => $clienteNombre, 'clienteTelefono' => $clienteTelefono ?? null,
                'clienteRnc' => $clienteRnc ?? null, 'ncfLabel' => $ncfLabel ?? null, 'condicion' => $condicion ?? null,
                'moneda' => $moneda,
            ])

            @include('documents.components.items', [
                'items' => $items, 'mostrarDescuento' => $mostrarDescuento,
            ])

            @include('documents.components.totals', [
                'totales' => $totales, 'mostrarDesglose' => $mostrarDesglose, 'mostrarDescuento' => $mostrarDescuento,
            ])

            @if ($type === 'quotation' && $vencimiento)
                <p class="bloque-texto"><b>Cotización válida hasta el {{ $vencimiento->format('d/m/Y') }}.</b></p>
            @endif

            @if (filled($notas ?? null))
                <div class="bloque-texto">
                    <p class="rot">Observaciones</p>
                    <p>{!! nl2br(e($notas)) !!}</p>
                </div>
            @endif

            @if (filled($condicionesTexto ?? null))
                <div class="bloque-texto">
                    <p class="rot">Condiciones</p>
                    <p>{!! nl2br(e($condicionesTexto)) !!}</p>
                </div>
            @endif

            @if ($mostrarFirma)
                @include('documents.components.signatures')
            @endif

            @include('documents.components.footer', [
                'type' => $type, 'company' => $company, 'piePersonalizado' => $piePersonalizado ?? null,
            ])
        </div>
    </div>
</body>
</html>
