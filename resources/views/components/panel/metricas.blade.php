{{--
    La tira de cifras: cuatro números grandes con su acento de color.

    El acento va a la IZQUIERDA y no como fondo teñido, porque un fondo de color compite con el dato
    que se viene a leer. Y se apaga a gris cuando el valor es cero: un cero en rojo asusta sin motivo,
    y «cero accesos fallidos» o «cero ventas anuladas» son la mejor noticia posible, no una alarma.

    Esa regla es justo la que se perdía al copiar el marcado: es fácil escribir el color fijo y
    quedarse con ceros en rojo por toda la aplicación.

    Uso:

        <x-panel.metricas :items="[
            ['valor' => 25, 'etiqueta' => 'sucesos 24 h', 'tono' => 'indigo', 'icono' => 'pulse'],
            ['valor' => 0,  'etiqueta' => 'accesos fallidos', 'tono' => 'rojo', 'icono' => 'ban'],
        ]" />

    `valor` puede ser un número —se formatea con separador de miles— o un texto ya compuesto, para
    cifras que llevan moneda o porcentaje.

    `icono` es opcional y sale del catálogo de App\Modules\Core\Support\Icons. Que sea UNO PROPIO por
    cifra, no el mismo repetido: un dibujo igual en todas las tarjetas no distingue ninguna, solo
    ocupa el sitio donde va el número. Un nombre que no exista no se pinta —y lo caza el test de
    tests/Unit/Core/IconosTest.php, que recorre lo que piden las vistas—.

    `tendencia` es opcional y la arma App\Modules\Core\Support\Tendencia, que ya decide el color:

        'tendencia' => Tendencia::calcular($antes, $ahora, subeEsBueno: false, detalle: '…')

    Aquí no se calcula nada ni se elige color, y ES A PROPÓSITO. Verde y rojo significan BIEN y MAL,
    no arriba y abajo: que suban las ventas es bueno y que suban los accesos fallidos es malo. Si
    esta plantilla pintara de verde todo lo que crece, un problema de seguridad se leería como una
    buena noticia. Quien pone la cifra es quien sabe qué dirección le conviene.
--}}
@props([
    /** @var array<int, array{valor: mixed, etiqueta: string, tono?: string, icono?: string, tendencia?: array{texto: string, signo: string, detalle: string}|null}> */
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
        {{-- El icono va abajo a la derecha, junto a la etiqueta, y NO arriba junto al número.
             A seis columnas la tarjeta mide unos 150 px y «20,755.00» se los come casi enteros: ahí
             el icono se montaba sobre el último dígito. La etiqueta, en cambio, sí puede partirse en
             dos líneas. Así que el icono le quita sitio a lo que puede cederlo y nunca al número.
             Lo coloca la rejilla del CSS; sin icono la columna vale cero y la tarjeta no cambia. --}}
        <div class="bmos-metrica" style="--tono: {{ $color }}">
            <p class="bmos-metrica-valor">{{ is_numeric($valor) ? number_format((float) $valor) : $valor }}</p>
            <p class="bmos-metrica-etq">{{ $m['etiqueta'] ?? '' }}</p>
            {{-- Toma el color del acento: refuerza lo que ya dice la barra de la izquierda en vez de
                 meter un color más, y se apaga a gris con ella cuando el valor es cero. --}}
            @if (filled($m['icono'] ?? null))
                <x-icono :name="$m['icono']" class="bmos-metrica-icono" />
            @endif

            {{-- La tendencia, debajo del todo. El color viene ya decidido: aquí no se sabe si crecer
                 es bueno. Y lleva flecha además de color, porque quien no distingue el verde del
                 rojo se quedaría sin saber si la cifra mejora o empeora. --}}
            @if (filled($t = $m['tendencia'] ?? null))
                <p class="bmos-metrica-tend" data-signo="{{ $t['signo'] }}" title="{{ $t['detalle'] }}">
                    @if ($t['texto'] !== '0%')
                        <x-icono :name="str_starts_with($t['texto'], '−') ? 'bajada' : 'subida'"
                                 class="bmos-metrica-flecha" />
                    @endif
                    <span>{{ $t['texto'] }}</span>
                </p>
            @endif
        </div>
    @endforeach
</div>
