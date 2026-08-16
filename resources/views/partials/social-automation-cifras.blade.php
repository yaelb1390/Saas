{{--
    Las cuatro cifras de una automatización, con su color.

    El color dice el PAPEL de cada una y su posición no cambia nunca, así que la vista aprende dónde
    mirar en dos tarjetas:

      · veces    (sky)    demanda que llega. Es una entrada, no un logro.
      · mensajes (indigo) el tono de la marca, en lo que el producto hace. La cifra protagonista.
      · personas (violet) vecino del índigo a propósito: personas ⊂ mensajes, se lee como hermana.
      · fallaron          el ÚNICO que cambia de color, y por eso se nota cuando cambia.

    El cero de «fallaron» va en VERDE y con la etiqueta «sin fallos». No en rojo: un cero rojo en
    cada tarjeta desensibiliza la vista en un día y luego un 3 de verdad no se registra. Y no en
    gris, que se lee como «no hay dato».

    `$stats` son las cifras; `$compacta` las encoge para la cabecera del reporte.
--}}
@php
    $huboFallos = $stats['failed'] > 0;

    $fichas = [
        ['tone-sky', $stats['triggered'], 'veces'],
        ['tone-indigo', $stats['sent'], 'mensajes'],
        ['tone-violet', $stats['people'], 'personas'],
        // Verde cuando no ha fallado nada, rojo cuando sí. Es el único que habla.
        [$huboFallos ? 'tone-rose' : 'tone-emerald', $stats['failed'], $huboFallos ? 'fallaron' : 'sin fallos'],
    ];
@endphp

<div class="bmos-cifras">
    @foreach ($fichas as [$tono, $valor, $etiqueta])
        <div class="bmos-cifra" data-tono="{{ $tono }}">
            <p class="bmos-cifra-valor">{{ number_format($valor) }}</p>
            <p class="bmos-cifra-etq">{{ $etiqueta }}</p>
        </div>
    @endforeach
</div>
