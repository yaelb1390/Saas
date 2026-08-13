<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Recuperar contraseña · BM Business OS</title>
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
            $enviado = session('status');
        @endphp
        <div class="bmos-auth-card {{ $hasError ? 'is-error' : '' }}">
            @if ($hasLogo)
                <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                     alt="BM Business OS" class="bmos-auth-logo-img">
            @else
                <div class="bmos-auth-logo">BM</div>
            @endif

            <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">¿Olvidaste tu contraseña?</h1>
            <p class="bmos-auth-sub">
                Escribe tu correo y te enviamos un enlace para crear una nueva.
            </p>

            {{-- El acuse es deliberadamente genérico: decir «ese correo no existe» le confirmaría a
                 cualquiera qué direcciones tienen cuenta en el sistema. Se responde igual siempre. --}}
            @if ($enviado)
                <div class="mb-4 flex items-start gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
                    </svg>
                    <span>
                        Si ese correo tiene una cuenta, te llegará un enlace en unos minutos.
                        Revisa también la carpeta de no deseados.
                    </span>
                </div>
            @endif

            @if ($hasError)
                <div class="bmos-auth-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="bmos-auth-form">
                @csrf

                <div>
                    <label for="email" class="bmos-field-label">Correo electrónico</label>
                    <input id="email" name="email" type="email" required autofocus
                           value="{{ old('email') }}" placeholder="tucorreo@empresa.com"
                           class="bmos-input {{ $hasError ? 'has-error' : '' }}">
                </div>

                <button type="submit" class="bmos-auth-btn mt-1">
                    Enviarme el enlace
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
</body>
</html>
