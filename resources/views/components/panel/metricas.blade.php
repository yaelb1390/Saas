{{--
    La tira de cifras: cuatro números grandes con su acento de color.

    El acento va a la IZQUIERDA y no como fondo teñido, porque un fondo de color compite con el dato
    que se viene a leer. Y se apaga a gris cuando el valor es cero: un cero en rojo asusta sin motivo,
    y «cero accesos fallidos» o «cero ventas anuladas» son la mejor noticia posible, no una alarma.

    Esa regla es justo la que se perdía al copiar el marcado: es fácil escribir el color fijo y
    quedarse con ceros en rojo por toda la aplicación.

    Uso:

        <x-panel.metricas :items="[
            ['valor' => 25, 'etiqueta' => 'sucesos 24 h', 'tono' => 'indigo'],
            ['valor' => 0,  'etiqueta' => 'accesos fallidos', 'tono' => 'rojo'],
        ]" />

    `valor` puede ser un número —se formatea con separador de miles— o un texto ya compuesto, para
    cifras que llevan moneda o porcentaje.
--}}
@props([
    /** @var array<int, array{valor: mixed, etiqueta: string, tono?: string}> */
    'items' => [],

    /*
     * Cuántas caben en una fila en pantalla ancha. Por omisión cuatro, que es lo que pide el CSS.
     *
     * Va como propiedad y no como clase de Tailwind porque en la versión 4 el modificador importante
     * es un SUFIJO —grid-cols-6! y no !grid-cols-6—: escrito al revés no es una clase válida, se
     * ignora en silencio, y seis cifras salen partidas en 4 + 2 sin que nada avise.
     */
    'columnas' => null,
])

@php
    $paleta = [
        'indigo' => '#6366f1',
        'ambar' => '#f59e0b',
        'azul' => '#0ea5e9',
        'rojo' => '#e11d48',
        'verde' => '#059669',
        'violeta' => '#7c3aed',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'bmos-pulso-grid']) }}
     @if ($columnas) style="--columnas: {{ (int) $columnas }}" @endif>
    @foreach ($items as $m)
        @php
            $valor = $m['valor'] ?? 0;
            // Cero, vacío o «RD$ 0,00»: en todos los casos no hay nada que destacar.
            $vacia = blank($valor) || (is_numeric($valor) && (float) $valor == 0.0);
            $color = $vacia ? '#cbd5e1' : ($paleta[$m['tono'] ?? 'indigo'] ?? $paleta['indigo']);
        @endphp
        <div class="bmos-metrica" style="--tono: {{ $color }}">
            <p class="bmos-metrica-valor">{{ is_numeric($valor) ? number_format((float) $valor) : $valor }}</p>
            <p class="bmos-metrica-etq">{{ $m['etiqueta'] ?? '' }}</p>
        </div>
    @endforeach
</div>
