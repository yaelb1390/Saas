{{-- Logo/marca a la izquierda; título del documento + número + fecha a la derecha. --}}
<table class="cab-tabla">
    <tr>
        <td style="width:56%">
            @if ($logo)
                <img src="{{ $logo }}" class="logo" alt="">
                <div class="comercial">{{ $company?->name ?? 'Comercio' }}</div>
            @else
                {{-- Sin logo no se deja un hueco: el nombre ocupa su sitio. --}}
                <div class="marca">{{ $company?->nombreParaDocumentos() ?? 'Comercio' }}</div>
            @endif

            <div class="senas">
                @if (filled($company?->address)){{ $company->address }}<br>@endif
                @if (filled($company?->phone)){{ $company->phone }}@endif
                @if (filled($company?->email)) &middot; {{ $company->email }}@endif
                @if (filled($company?->tax_id))<br>RNC {{ $company->tax_id }}@endif
            </div>
        </td>
        <td class="doc-titulo">
            <div class="h">{{ $titulo }}</div>
            <div class="cod">{{ $codigo }}</div>

            <div class="doc-meta">
                <div class="fila"><span class="k">Fecha:</span> {{ $fecha?->format('d/m/Y') }}</div>

                @if ($type === 'invoice' && filled($ncf ?? null))
                    <div class="fila"><span class="k">NCF:</span> {{ $ncf }}</div>
                @endif

                @if ($type === 'quotation' && $vencimiento)
                    <div class="fila"><span class="k">Válida hasta:</span> {{ $vencimiento->format('d/m/Y') }}</div>
                @endif

                @if (filled($vendedor))
                    <div class="fila"><span class="k">Vendedor:</span> {{ $vendedor }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>
