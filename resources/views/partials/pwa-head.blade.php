{{-- Etiquetas que hacen la app instalable en cualquier dispositivo (Android, iOS, escritorio).
     Se incluye en el <head> de todos los layouts para no duplicarlas. --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#ffffff">

{{-- iOS no usa el manifest para instalar: necesita sus propias etiquetas. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="BM Business">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
