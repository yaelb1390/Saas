{{-- Subtotal, descuento, ITBIS y total. --}}
<table class="totales">
    @if ($mostrarDesglose)
        <tr>
            <td class="lbl">Subtotal</td>
            <td class="val">{{ money((float) $totales['subtotal']) }}</td>
        </tr>
        <tr>
            <td class="lbl">ITBIS</td>
            <td class="val">{{ money((float) $totales['tax']) }}</td>
        </tr>
    @endif

    @if ($mostrarDescuento && bccomp((string) $totales['discount'], '0', 2) > 0)
        <tr>
            <td class="lbl">Descuento</td>
            <td class="val">−{{ money((float) $totales['discount']) }}</td>
        </tr>
    @endif

    <tr class="final">
        <td class="lbl">Total</td>
        <td class="val">{{ money((float) $totales['total']) }}</td>
    </tr>
</table>
