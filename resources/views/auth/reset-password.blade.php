<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Nueva contraseña · BM Business OS</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bmos-auth-body">
    <div class="bmos-auth">
        @php
            $hasError = $errors->any();
            $logoPath = public_path('images/bm-logo.png');
            $hasLogo = file_exists($logoPath);
        @endphp
        <div class="bmos-auth-card {{ $hasError ? 'is-error' : '' }}" x-data="{ show: false }">
            @if ($hasLogo)
                <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                     alt="BM Business OS" class="bmos-auth-logo-img">
            @else
                <div class="bmos-auth-logo">BM</div>
            @endif

            <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">Crea tu nueva contraseña</h1>
            <p class="bmos-auth-sub">Elige una que no uses en otros sitios.</p>

            @if ($hasError)
                <div class="bmos-auth-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="bmos-auth-form">
                @csrf

                {{-- El token identifica la petición y el correo, a quién pertenece. Van ocultos
                     porque vienen del enlace: si el usuario pudiera editarlos, cambiaría la
                     contraseña de otra persona. --}}
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label for="email" class="bmos-field-label">Correo electrónico</label>
                    <input id="email" name="email" type="email" required readonly
                           value="{{ old('email', $request->query('email')) }}"
                           class="bmos-input {{ $hasError ? 'has-error' : '' }} bg-slate-100 text-slate-500">
                </div>

                <div>
                    <label for="password" class="bmos-field-label">Nueva contraseña</label>
                    <input id="password" name="password" required autofocus autocomplete="new-password"
                           placeholder="••••••••" :type="show ? 'text' : 'password'" class="bmos-input">
                </div>

                <div>
                    <label for="password_confirmation" class="bmos-field-label">Repetir contraseña</label>
                    <input id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                           placeholder="••••••••" :type="show ? 'text' : 'password'" class="bmos-input">
                </div>

                <label class="-mt-1 flex items-center gap-2 text-sm text-slate-500">
                    <input type="checkbox" x-model="show" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Mostrar contraseña
                </label>

                <button type="submit" class="bmos-auth-btn mt-1">
                    Guardar y entrar
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </button>
            </form>

            <p class="bmos-auth-alt text-center text-sm text-slate-500">
                <a href="{{ route('login') }}" class="font-semibold text-indigo-600 hover:underline">← Volver a iniciar sesión</a>
            </p>

            <p class="bmos-auth-copy text-center text-xs text-slate-400">© {{ date('Y') }} BM Business OS</p>
        </div>
    </div>
    <style>[x-cloak]{display:none!important}</style>
</body>
</html>
