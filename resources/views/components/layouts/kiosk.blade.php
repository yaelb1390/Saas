{{-- Layout del terminal de venta: la pantalla entera para vender y nada más.

     No reutiliza `layouts.app` porque a aquel le falta el `[x-cloak]` (todo el POS lo usa y sin él
     parpadea el contenido oculto al cargar) y le sobran los banners de instalación.

     Deliberadamente NO incluye:
       · barra lateral y menú     → el cajero no navega
       · chip de cambio de empresa → cambiar de empresa lo sacaría del terminal
       · campana de alertas        → además ejecuta AlertService::forCurrentCompany() en cada render
       · aviso de suscripción      → su modal ocupa la pantalla y un cajero no puede resolver un pago
                                     (si la suscripción falla, EnsureSubscriptionActive ya desvía) --}}
@php
    $user = auth()->user();
    $company = app(\App\Modules\Core\Tenancy\CurrentCompany::class)->model();
@endphp
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- `viewport-fit=cover` y el bloqueo de zoom: en un terminal táctil, el pellizco accidental
         mientras se cobra es un estorbo, no una función. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Venta rápida' }}</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    @include('partials.pwa-head', ['manifest' => 'manifest-pos.json'])
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100">
<div class="bmos-kiosk" x-data="kiosko()">
    {{-- Franja de contexto: quién cobra, en qué negocio, y las dos únicas salidas. --}}
    <header class="bmos-kiosk-bar">
        <div class="flex min-w-0 items-center gap-3">
            @if (file_exists(public_path('images/bm-mark.png')))
                <img src="{{ asset('images/bm-mark.png') }}" alt="" class="h-7 w-7 shrink-0 object-contain">
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ $company?->name ?? 'BM Business OS' }}</p>
                <p class="truncate text-xs text-slate-400">{{ $user?->name }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            {{-- Solo se ofrece si el navegador lo soporta y no estamos ya en una app instalada:
                 dentro de la PWA la pantalla ya es completa y el botón sobraría. --}}
            <button type="button" x-show="soportado && !instalada" x-cloak @click="alternar()"
                    class="bmos-kiosk-btn" :aria-label="activa ? 'Salir de pantalla completa' : 'Pantalla completa'">
                <svg x-show="!activa" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/>
                </svg>
                <svg x-show="activa" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/>
                </svg>
            </button>

            <form method="POST" action="{{ route('logout') }}"
                  onsubmit="return confirm('¿Cerrar la sesión del terminal?')">
                @csrf
                <button type="submit" class="bmos-kiosk-btn" aria-label="Cerrar sesión">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                    </svg>
                </button>
            </form>
        </div>
    </header>

    <main class="bmos-kiosk-main">
        {{ $slot }}
    </main>
</div>

{{-- Obligatorio: la pantalla usa x-cloak para no enseñar lo oculto durante la carga de Alpine. --}}
<style>[x-cloak]{display:none!important}</style>
</body>
</html>
