{{--
    Un icono del panel.

    Dibuja el SVG entero para que quien lo use no tenga que repetir el `viewBox`, el `fill` ni el
    grosor del trazo en cada sitio. El color sale de `currentColor`, así que se hereda del texto de
    alrededor y no hay que pasarlo.

    Uso:  <x-icono name="cash" class="h-5 w-5" />

    Si el nombre no existe no se pinta nada, en vez de tumbar la pantalla por un dibujo. De que nadie
    pida uno inexistente se encarga el test que recorre los nombres usados.
--}}
@props([
    'name',

    /*
     * El grosor del trazo, como propiedad y no suelto en los atributos.
     *
     * Si llegara por el saco de atributos el SVG saldría con DOS stroke-width —el que pasa quien lo
     * usa y el de aquí— y quedaría a merced de cuál gana al parsear. Declarado como propiedad solo
     * hay uno.
     */
    'strokeWidth' => '1.7',
])

@php
    $trazo = \App\Modules\Core\Support\Icons::path($name);
@endphp

@if ($trazo !== '')
    <svg {{ $attributes->merge(['class' => 'bmos-icono']) }}
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $strokeWidth }}" aria-hidden="true">
        {!! $trazo !!}
    </svg>
@endif
