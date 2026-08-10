{{-- Etiquetas que hacen la app instalable en cualquier dispositivo (Android, iOS, escritorio).
     Se incluye en el <head> de todos los layouts para no duplicarlas.

     El manifiesto es parametrizable porque el terminal de venta usa uno propio
     (`manifest-pos.json`): arranca directo en la pantalla de cobro, a pantalla completa y en
     horizontal, mientras que el panel arranca en el dashboard con la barra del navegador. --}}
@php($manifiesto = $manifest ?? 'manifest.json')
@php($barraEstado = ($manifest ?? null) === 'manifest-pos.json' ? 'black-translucent' : 'default')

<link rel="manifest" href="{{ asset($manifiesto) }}">
<meta name="theme-color" content="#ffffff">

{{-- iOS no usa el manifest para instalar: necesita sus propias etiquetas. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="{{ $barraEstado }}">
<meta name="apple-mobile-web-app-title" content="BM Business">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
