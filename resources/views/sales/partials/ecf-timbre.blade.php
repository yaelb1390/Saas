{{--
    Timbre del e-CF en el ticket de 80 mm [IT §18; DT pp.40–42]: tipo, e-NCF, vencimiento, fecha de
    firma, QR (25 mm, mínimo 22 mm) y código de seguridad bajo el QR. Estilos en línea y sin flex:
    sirve igual para el ticket HTML y para el PDF de dompdf.

    array $timbre  ver ElectronicInvoicing\Printing\Timbre::for()
--}}
<div style="text-align: center; font-size: 8pt; line-height: 1.35;">
    @if (! empty($timbre['prueba']))
        {{-- Modo «En paralelo»: el comprobante del cliente es el NCF de arriba; esto es la prueba. --}}
        <div style="font-weight: bold; font-size: 9pt;">e-CF DE PRUEBA</div>
        <div style="margin-bottom: 2pt;">Tu comprobante fiscal es el NCF de arriba.</div>
    @endif
    <div style="font-weight: bold;">{{ $timbre['tipo'] }}</div>
    <div>e-NCF: <strong>{{ $timbre['encf'] }}</strong></div>
    @if ($timbre['vence'])
        <div>Vence: {{ $timbre['vence'] }}</div>
    @endif
    @if ($timbre['fecha_firma'])
        <div>Firma digital: {{ $timbre['fecha_firma'] }}</div>
    @endif
    @if ($timbre['contingencia'])
        <div style="font-weight: bold;">{{ $timbre['contingencia'] }}</div>
    @endif
    @unless ($timbre['fiscal'])
        <div style="font-weight: bold;">Ambiente {{ $timbre['ambiente'] }}: sin validez fiscal</div>
    @endunless
    <img src="{{ $timbre['qr'] }}" alt="QR" style="width: 71pt; height: 71pt; margin: 3pt auto 1pt; display: block;">
    <div style="font-weight: bold;">Código de seguridad: {{ $timbre['codigo'] }}</div>
</div>
