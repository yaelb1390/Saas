{{--
    Timbre de la representación impresa de un e-CF [IT §18; DT pp.40–42]: QR que lleva a la consulta
    de la DGII (≥ 22 × 22 mm: aquí 25 mm), código de seguridad bajo el QR, fecha de firma, vencimiento
    de la secuencia y, si aplica, la leyenda de contingencia [IT §19]. Maquetado con tabla (dompdf).

    array $timbre  ver ElectronicInvoicing\Printing\Timbre::for()
--}}
<table style="width: 100%; border-collapse: collapse; margin-top: 14pt;">
    <tr>
        <td style="width: 82pt; vertical-align: top; text-align: center;">
            <img src="{{ $timbre['qr'] }}" alt="QR" style="width: 71pt; height: 71pt;">
            <p style="font-size: 7.6pt; color: #334155; margin-top: 2pt;">Código de seguridad: <b>{{ $timbre['codigo'] }}</b></p>
        </td>
        <td style="vertical-align: top; padding-left: 8pt; font-size: 8pt; color: #475569;">
            @if (! empty($timbre['prueba']))
                {{-- Modo «En paralelo»: el comprobante es la factura B; esto es el e-CF de prueba. --}}
                <p style="font-weight: bold; color: #b91c1c; font-size: 9pt;">e-CF DE PRUEBA · el comprobante fiscal es el NCF de esta factura</p>
            @endif
            <p style="font-weight: bold; color: #0f172a; font-size: 9pt;">{{ $timbre['tipo'] }}</p>
            <p style="margin-top: 2pt;">e-NCF: <b>{{ $timbre['encf'] }}</b></p>
            @if ($timbre['vence'])
                <p style="margin-top: 1.5pt;">Fecha de vencimiento de la secuencia: {{ $timbre['vence'] }}</p>
            @endif
            @if ($timbre['fecha_firma'])
                <p style="margin-top: 1.5pt;">Fecha de firma digital: {{ $timbre['fecha_firma'] }}</p>
            @endif
            @if ($timbre['contingencia'])
                <p style="margin-top: 4pt; font-weight: bold; color: #b45309;">{{ $timbre['contingencia'] }}</p>
            @endif
            @unless ($timbre['fiscal'])
                <p style="margin-top: 4pt; font-weight: bold; color: #b91c1c;">Ambiente {{ $timbre['ambiente'] }}: sin validez fiscal.</p>
            @endunless
        </td>
    </tr>
</table>
