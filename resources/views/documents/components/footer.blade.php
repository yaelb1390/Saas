{{-- Pie del documento: quién lo generó, y el aviso fiscal cuando aplica. --}}
<div class="pie">
    @if (filled($piePersonalizado))
        {{ $piePersonalizado }}<br>
    @endif

    Documento generado por {{ $company?->nombreParaDocumentos() ?? 'la empresa' }} &middot; República Dominicana

    @if ($type === 'quotation')
        <br>Este documento es una cotización, no un comprobante fiscal.
    @endif
</div>
