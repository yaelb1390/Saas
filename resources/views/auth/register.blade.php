<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Crear cuenta · BM Business OS</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bmos-auth-body">
    <div class="bmos-auth">
        @php
            $hasError = $errors->any();
            $logoPath = public_path('images/bm-logo.png');
            $hasLogo = file_exists($logoPath);
            $oldModules = (array) old('modules', []);
        @endphp
        <div class="bmos-auth-card {{ $hasError ? 'is-error' : '' }}" style="max-width:40rem">
            @if ($hasLogo)
                <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                     alt="BM Business OS" class="bmos-auth-logo-img">
            @else
                <div class="bmos-auth-logo">BM</div>
            @endif
            <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">Crea tu cuenta gratis</h1>
            <p class="bmos-auth-sub">
                Prueba {{ $trialDays }} días sin costo. Sin tarjeta.
            </p>

            {{-- Aviso: los datos son de prueba y se borran tras vencer. --}}
            <div class="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="mt-0.5 h-5 w-5 shrink-0 text-amber-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M12 3l9 16H3l9-16Z"/>
                </svg>
                <p>
                    <b>Modo de prueba.</b> Todo lo que registres durante la prueba se considera
                    <b>información de prueba</b> y se <b>eliminará automáticamente 24 horas</b> después de que
                    termine tu prueba de {{ $trialDays }} días si no activas un plan.
                </p>
            </div>

            @if ($hasError)
                <div class="bmos-auth-error mb-4">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5 shrink-0">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="bmos-auth-form" x-data="{ show: false }">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="company_name" class="bmos-field-label">Nombre de tu negocio</label>
                        <input id="company_name" name="company_name" type="text" required autofocus
                               value="{{ old('company_name') }}" placeholder="Ej: Préstamos La Confianza"
                               class="bmos-input">
                    </div>
                    <div>
                        <label for="owner_name" class="bmos-field-label">Tu nombre</label>
                        <input id="owner_name" name="owner_name" type="text" required
                               value="{{ old('owner_name') }}" placeholder="Nombre y apellido" class="bmos-input">
                    </div>
                    <div>
                        <label for="owner_email" class="bmos-field-label">Correo electrónico</label>
                        <input id="owner_email" name="owner_email" type="email" required
                               value="{{ old('owner_email') }}" placeholder="tucorreo@empresa.com" class="bmos-input">
                    </div>
                    <div>
                        <label for="password" class="bmos-field-label">Contraseña</label>
                        <input id="password" name="password" required placeholder="••••••••"
                               :type="show ? 'text' : 'password'" class="bmos-input">
                    </div>
                    <div>
                        <label for="password_confirmation" class="bmos-field-label">Repetir contraseña</label>
                        <input id="password_confirmation" name="password_confirmation" required placeholder="••••••••"
                               :type="show ? 'text' : 'password'" class="bmos-input">
                    </div>
                    <label class="sm:col-span-2 -mt-1 flex items-center gap-2 text-sm text-slate-500">
                        <input type="checkbox" x-model="show" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        Mostrar contraseña
                    </label>
                </div>

                {{-- Selección de módulos a probar --}}
                <div>
                    <label class="bmos-field-label">¿Qué módulos quieres probar?</label>
                    <p class="mb-2 text-xs text-slate-500">Selecciona solo los que tu negocio necesita; podrás activar más al contratar tu plan.</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach ($modules as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 transition hover:border-indigo-300 hover:bg-indigo-50/40 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50">
                                <input type="checkbox" name="modules[]" value="{{ $key }}"
                                       @checked(in_array($key, $oldModules, true))
                                       class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <button type="submit" class="bmos-auth-btn mt-2">
                    Crear mi cuenta y empezar la prueba
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                    </svg>
                </button>
            </form>

            <p class="mt-5 text-center text-sm text-slate-500">
                ¿Ya tienes cuenta?
                <a href="{{ route('login') }}" class="font-semibold text-indigo-600 hover:underline">Inicia sesión</a>
            </p>
        </div>
    </div>
    <style>[x-cloak]{display:none!important}</style>
</body>
</html>
