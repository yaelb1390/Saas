{{--
    La tabla dinámica. SIEMPRE se pintan todas las líneas de $items: la densidad (ver el <style>
    del layout) solo cambia tipografía/espaciado, nunca esconde ni recorta una fila. Si no caben en
    una página, dompdf pagina solo y repite este <thead> en cada hoja nueva (display: table-header-
    group), porque es una tabla real con <thead>/<tbody> y no un div maquetado a mano.
--}}
<table class="items">
    <thead>
        <tr>
            <th>#</th>
            <th>Descripción</th>
            <th class="num">Cantidad</th>
            <th class="num">Precio</th>
            <th class="num">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    {{ $item['description'] }}
                    @if ($mostrarDescuento && bccomp((string) $item['discount'], '0', 2) > 0)
                        <div class="descuento-linea">Descuento: −{{ money((float) $item['discount']) }}</div>
                    @endif
                </td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.') }}</td>
                <td class="num">{{ money((float) $item['unit_price']) }}</td>
                <td class="num">{{ money((float) $item['subtotal']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
