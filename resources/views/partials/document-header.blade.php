@php
    use App\Modules\Core\Support\CompanyLogoStore;

    /*
     * Cabecera de todo documento que recibe un cliente: recibo de venta y recibo de cobro, en
     * pantalla y en PDF de 80 mm.
     *
     * Vive en un solo sitio porque son cuatro vistas con la misma cabecera: mantenerlas por separado
     * garantizaba que un día el logo saliera en el recibo de pantalla y no en el impreso, o que el
     * teléfono se actualizara en uno y en el otro no.
     *
     * `$pdf` cambia CÓMO viaja el logo, no si sale:
     *
     *  - En pantalla, una URL: el navegador la pide con la sesión del usuario.
     *  - En PDF, el fichero incrustado en base64. dompdf no lleva las cookies del usuario, así que
     *    una URL protegida le devolvería un 404 y el documento saldría sin logo.
     */
    $pdf = $pdf ?? false;
    $logo = $pdf ? CompanyLogoStore::dataUri($company) : $company?->logoUrl();

    // En el rollo térmico el logo va contenido: una imagen grande tarda en salir y, con mucho color,
    // la impresora la convierte en un borrón gris.
    //
    // El alto sale de la constante y no de un número escrito aquí porque quien calcula el ALTO DEL
    // PAPEL usa ese mismo valor: si los dos se separan, el recibo se parte en dos páginas.
    $anchoLogo = $pdf ? '120pt' : '150px';
    $altoLogo = $pdf ? CompanyLogoStore::PDF_ALTO_PT.'pt' : '60px';
@endphp

<div class="center">
    @if ($logo)
        <img src="{{ $logo }}" alt=""
             style="max-width: {{ $anchoLogo }}; max-height: {{ $altoLogo }}; margin: 0 auto 4px; display: block;">
    @endif

    <div class="brand">{{ $company?->nombreParaDocumentos() ?? 'BM Business OS' }}</div>
    @if ($company?->tax_id)<div class="muted">RNC: {{ $company->tax_id }}</div>@endif
    @if ($company?->address)<div class="muted">{{ $company->address }}</div>@endif
    @if ($company?->phone)<div class="muted">Tel: {{ $company->phone }}</div>@endif
</div>
