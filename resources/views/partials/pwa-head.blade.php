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

      - DOS etiquetas, no una: Chrome, Edge y Safari eligen la que coincide con el tema del sistema
        —claro u oscuro—, sin JavaScript. Es el mecanismo estándar para un color "adaptable".
      - El claro usa el índigo de marca (--bmos-primary, el mismo de los botones); el oscuro usa el
        azul marino de la barra lateral (--bmos-sidebar-from) — ya es un color de la propia marca,
        no uno inventado para esto, y no exige que el resto de la app tenga modo oscuro para que la
        barra del navegador se vea bien con el sistema en oscuro.
--}}
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#4f46e5">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#171a2b">

{{-- iOS no usa el manifest para instalar: necesita sus propias etiquetas. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="{{ $barraEstado }}">
<meta name="apple-mobile-web-app-title" content="BM Business">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
