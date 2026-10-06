{{--
    Etiqueta de estado de Facturación electrónica con daisyUI.

    Los estados ya saben su color en el sistema del panel (`badge()` de SetupStatus/EcfStatus devuelve
    `badge-green`, `badge-red`…): aquí se traduce una sola vez a su equivalente de daisyUI, para que las
    seis pantallas de e-CF no repitan la tabla. Solo funciona dentro de `data-theme="bmia"`.

    Uso: <x-panel.ecf-badge tono="badge-green">Aceptado</x-panel.ecf-badge>
--}}
@props(['tono' => 'badge-gray', 'grande' => false])

@php
    $clase = [
        'badge-green' => 'd-badge-success',
        'badge-amber' => 'd-badge-warning',
        'badge-red' => 'd-badge-error',
        'badge-blue' => 'd-badge-info',
        'badge-violet' => 'd-badge-accent',
    ][$tono] ?? 'd-badge-neutral d-badge-soft';
@endphp

<span {{ $attributes->class(['d-badge whitespace-nowrap', $clase, 'd-badge-lg' => $grande, 'd-badge-sm' => ! $grande]) }}>{{ $slot }}</span>
