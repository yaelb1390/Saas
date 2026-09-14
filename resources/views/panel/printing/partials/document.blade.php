@php
    /**
     * El documento genérico que imprime el Centro de Impresión: el mismo esqueleto para un ticket de
     * venta, una factura A4 o un reporte, porque los tres son en el fondo lo mismo —un encabezado, unas
     * líneas, unos totales, un pie— con distinto ancho de papel y distintos campos encendidos.
     *
     * Recibe:
     *   $layout    array  — el contrato de TemplateService::layoutPorDefecto().
     *   $paperSize string — clave de PaperSize.
     *   $anchoMm   int    — ancho real en mm (resuelto ya el caso «personalizado»).
     *   $esRollo   bool   — true si es un rollo térmico continuo (sin alto fijo).
     *   $company   Company|null
     *   $data      PrintableDocumentData
     *   $typeLabel string — «Ticket de venta», «Factura A4»… para el encabezado del documento.
     *   $pdf       bool   — true cuando el logo debe ir embebido (impresión), no por URL (vista previa).
     */
    use App\Modules\Core\Support\CompanyLogoStore;

    $pdf = $pdf ?? false;
    $logo = ($layout['logo']['enabled'] ?? true) ? ($pdf ? CompanyLogoStore::dataUri($company) : $company?->logoUrl()) : null;

    $tamanoLogo = match ($layout['logo']['size'] ?? 'md') {
        'sm' => ['w' => '120px', 'h' => '50px'],
        'lg' => ['w' => '220px', 'h' => '100px'],
        default => ['w' => '180px', 'h' => '80px'],
    };

    $tamanoFuente = match ($layout['font_size'] ?? 'md') {
        'sm' => '11px',
        'lg' => '14px',
        default => '12.5px',
    };

    $alinear = static fn (string $clave, string $porDefecto = 'center'): string => match ($layout[$clave] ?? $porDefecto) {
        'left' => 'left',
        'right' => 'right',
        default => 'center',
    };

    // Ancho del contenedor: los rollos térmicos se miden en mm exactos; una hoja se limita a un
    // ancho de lectura cómodo en pantalla, porque 210mm de A4 completo no caben en una vista previa.
    $anchoContenedor = $esRollo ? $anchoMm.'mm' : min(480, ($anchoMm ?: 216) * 3.2).'px';
@endphp
<div class="bmos-doc" style="width: {{ $anchoContenedor }}; font-size: {{ $tamanoFuente }};">
    <div style="text-align: {{ $alinear('align_header') }};">
        @if ($logo)
            <img src="{{ $logo }}" alt=""
                 style="max-width: {{ $tamanoLogo['w'] }}; max-height: {{ $tamanoLogo['h'] }}; margin: 0 auto 4px; display: block;">
        @endif

        @if ($layout['show_company_name'] ?? true)
            <div class="bmos-doc-brand">{{ $company?->nombreParaDocumentos() ?? 'BM Business OS' }}</div>
        @endif
        @if (($layout['show_rnc'] ?? false) && $company?->tax_id)
            <div class="bmos-doc-muted">RNC: {{ $company->tax_id }}</div>
        @endif
        @if (($layout['show_address'] ?? true) && $company?->address)
            <div class="bmos-doc-muted">{{ $company->address }}</div>
        @endif
        @if (($layout['show_phone'] ?? true) && $company?->phone)
            <div class="bmos-doc-muted">Tel: {{ $company->phone }}</div>
        @endif

        @if (! empty($layout['header_text']))
            <div class="bmos-doc-muted" style="margin-top: 4px;">{{ $layout['header_text'] }}</div>
        @endif
    </div>

    <hr class="bmos-doc-sep">

    <div style="text-align: center;">
        <strong>{{ strtoupper($typeLabel) }}</strong>
        @if ($data->reference)<br><span class="bmos-doc-muted">{{ $data->reference }}</span>@endif
        @if (($layout['show_ncf'] ?? false) && ! empty($data->meta))
            @foreach ($data->meta as $m)
                @if (($m['label'] ?? '') === 'NCF')
                    <div class="bmos-doc-ncf">NCF: {{ $m['value'] }}</div>
                @endif
            @endforeach
        @endif
    </div>

    <hr class="bmos-doc-sep">

    @foreach ($data->meta as $m)
        @continue(($m['label'] ?? '') === 'NCF')
        <div class="bmos-doc-row"><span class="bmos-doc-muted">{{ $m['label'] }}</span><span>{{ $m['value'] }}</span></div>
    @endforeach

    @if (! empty($data->lines))
        <hr class="bmos-doc-sep">
        <table class="bmos-doc-items">
            @foreach ($data->lines as $linea)
                <tr>
                    @if ($linea['qty'] ?? null)<td class="bmos-doc-qty">{{ $linea['qty'] }}</td>@endif
                    <td>
                        {{ $linea['description'] }}
                        @if (! empty($linea['detail']))<div class="bmos-doc-muted">{{ $linea['detail'] }}</div>@endif
                    </td>
                    @if ($linea['amount'] ?? null)<td class="bmos-doc-num">{{ $linea['amount'] }}</td>@endif
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($data->totals))
        <hr class="bmos-doc-sep">
        <div style="text-align: {{ $alinear('align_totals', 'right') }};">
            @foreach ($data->totals as $t)
                <div class="bmos-doc-row {{ ($t['emphasis'] ?? false) ? 'bmos-doc-grand' : '' }}">
                    <span class="bmos-doc-muted">{{ $t['label'] }}</span><span>{{ $t['value'] }}</span>
                </div>
            @endforeach
        </div>
    @endif

    @foreach ($layout['extra_fields'] ?? [] as $campo)
        <div class="bmos-doc-row"><span class="bmos-doc-muted">{{ $campo['label'] ?? '' }}</span><span>{{ $campo['value'] ?? '' }}</span></div>
    @endforeach

    @if ($data->note || ! empty($layout['footer_text']))
        <hr class="bmos-doc-sep">
        <div class="bmos-doc-muted" style="text-align: center;">
            @if ($data->note){{ $data->note }}<br>@endif
            {{ $layout['footer_text'] ?? '' }}
        </div>
    @endif

    {{--
        QR y código de barras se dibujan EN EL NAVEGADOR (canvas/SVG vía `resources/js/printing/codes.js`,
        cargado por quien incluya esta plantilla) y no en el servidor: así la vista previa del editor
        se actualiza al instante con cada tecla, sin ir y volver al servidor por una imagen, y no hace
        falta sumar una librería de generación de QR en PHP para un dato que el navegador ya sabe pintar.
        El QR por Bluetooth es otro camino —EscPosBuilder::qr()— y lo genera la propia impresora térmica.
    --}}
    @if (($layout['qr']['enabled'] ?? false) && $data->reference)
        <div style="text-align: center; margin-top: 8px;">
            <canvas class="bmos-doc-qr" data-qr-content="{{ $data->reference }}" width="90" height="90"></canvas>
        </div>
    @endif
    @if (($layout['barcode']['enabled'] ?? false) && $data->reference)
        <div style="text-align: center; margin-top: 8px;">
            <svg class="bmos-doc-barcode" data-barcode-content="{{ $data->reference }}"></svg>
        </div>
    @endif
</div>
