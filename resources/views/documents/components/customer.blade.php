{{-- Dos tarjetas: datos del cliente e información fiscal del documento. --}}
<table class="dos-cols">
    <tr>
        <td>
            <div class="tarjeta tarjeta-gris tarjeta-pad">
                <p class="card-rotulo">Cliente</p>
                <p class="card-linea"><b>{{ $clienteNombre }}</b></p>
                @if (filled($clienteRnc))
                    <p class="card-linea">RNC/Cédula: {{ $clienteRnc }}</p>
                @endif
                @if (filled($clienteTelefono))
                    <p class="card-linea">Tel: {{ $clienteTelefono }}</p>
                @endif
            </div>
        </td>
        <td>
            <div class="tarjeta tarjeta-gris tarjeta-pad">
                <p class="card-rotulo">Información fiscal</p>
                <p class="card-linea">
                    Tipo: <b>{{ $type === 'invoice' ? ($ncfLabel ?? 'Factura') : 'Cotización' }}</b>
                </p>
                <p class="card-linea">Moneda: {{ $moneda }}</p>
                @if (filled($condicion))
                    <p class="card-linea">Condición: {{ $condicion }}</p>
                @endif
            </div>
        </td>
    </tr>
</table>
