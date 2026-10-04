{{-- Etiquetas que hacen la app instalable en cualquier dispositivo (Android, iOS, escritorio).
     Se incluye en el <head> de todos los layouts para no duplicarlas.

     El manifiesto es parametrizable porque el terminal de venta usa uno propio
     (`manifest-pos.json`): arranca directo en la pantalla de cobro, a pantalla completa y en
     horizontal, mientras que el panel arranca en el dashboard con la barra del navegador. --}}
@php($manifiesto = $manifest ?? 'manifest.json')
@php($barraEstado = ($manifest ?? null) === 'manifest-pos.json' ? 'black-translucent' : 'default')

<link rel="manifest" href="{{ asset($manifiesto) }}">
{{--
    El color de la barra del título/pestaña. Antes era blanco fijo —ni destacaba la marca ni
    reaccionaba a nada—, y encima el favicon estaba roto (0 bytes), así que Windows enseñaba un
    icono genérico de carpeta en vez del logo. Ahora:

      - Un solo color, el azul marino de la barra lateral y la superior (--bmos-sidebar-from), con
        el sistema claro u oscuro. Antes el tema claro usaba el índigo de los botones y la franja de
        título de la app de escritorio salía como un bloque azul que no casaba con la barra oscura
        de justo debajo. Va igual en manifest.json y manifest-pos.json: las ventanas que no son
        HTML (el visor de PDF) no tienen esta etiqueta y toman el color del manifiesto.
--}}
<meta name="theme-color" content="#171a2b">

{{-- iOS no usa el manifest para instalar: necesita sus propias etiquetas. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="{{ $barraEstado }}">
<meta name="apple-mobile-web-app-title" content="BM Business">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
