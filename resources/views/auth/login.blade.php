<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iniciar sesión · BM Business OS</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <div class="bmos-auth">
        @php
            $hasError = $errors->any();
            $logoPath = public_path('images/bm-logo.png');
            $hasLogo = file_exists($logoPath);
        @endphp
        <div class="bmos-auth-card {{ $hasError ? 'is-error' : '' }}" x-data="{ show: false }">
            @if ($hasLogo)
                <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                     alt="BM Business OS" class="mx-auto mb-5 w-60 max-w-full object-contain">
            @else
                <div class="bmos-auth-logo">BM</div>
                <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">BM Business OS</h1>
            @endif
            <p class="mt-1 mb-7 text-center text-sm text-slate-500">Inicia sesión para acceder a tu panel</p>

            @if ($hasError)
                <div class="bmos-auth-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="bmos-field-label">Correo electrónico</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}" placeholder="tucorreo@empresa.com"
                           class="bmos-input {{ $hasError ? 'has-error' : '' }}">
                </div>

                <div>
                    <label for="password" class="bmos-field-label">Contraseña</label>
                    <div class="relative">
                        <input id="password" name="password" required placeholder="••••••••"
                               :type="show ? 'text' : 'password'"
                               class="bmos-input pr-11 {{ $hasError ? 'has-error' : '' }}">
                        <button type="button" @click="show = !show" tabindex="-1"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"
                                :aria-label="show ? 'Ocultar contraseña' : 'Mostrar contraseña'">
                            <svg x-show="!show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                            </svg>
                            <svg x-show="show" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.243 4.243L9.88 9.88"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="remember" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Recordarme
                    </label>
                </div>

                <button type="submit" class="bmos-auth-btn mt-2">
                    Iniciar sesión
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </button>
            </form>

            <p class="mt-6 text-center text-xs text-slate-400">© {{ date('Y') }} BM Business OS</p>
        </div>
    </div>
    <style>[x-cloak]{display:none!important}</style>
</body>
</html>
