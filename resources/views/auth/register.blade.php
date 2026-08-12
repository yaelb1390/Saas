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
        @endphp
        {{-- Más ancha que el login: aquí caben tres planes en fila en escritorio. --}}
        <div class="bmos-auth-card {{ $hasError ? 'is-error' : '' }}" style="max-width:52rem">
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

                {{-- Elección del plan a probar.
                     Se muestran los módulos de cada plan, pero NO se pueden elegir sueltos: probar
                     exactamente lo que se va a comprar es lo que hace que al terminar la prueba el
                     paso natural sea pagar ese mismo plan.
                     Sin JavaScript: el estado marcado sale de `has-[:checked]:`, el mismo recurso
                     que ya usaban las casillas de módulos. --}}
                <div>
                    <label class="bmos-field-label">¿Qué plan quieres probar?</label>
                    <p class="mb-3 text-xs text-slate-500">
                        Gratis los {{ $trialDays }} días, sin tarjeta. Podrás cambiarlo cuando actives tu plan.
                    </p>

                    @if ($plans->isEmpty())
                        <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Ahora mismo no hay planes disponibles. Escríbenos y te damos de alta a mano.
                        </p>
                    @else
                        {{-- Clases literales, nunca interpoladas: Tailwind solo genera las que
                             encuentra escritas tal cual al escanear, y una construida al vuelo
                             quedaría sin estilo. --}}
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($plans as $plan)
                                <label class="bmos-plan-pick bmos-plan-pick--nivel{{ min($loop->index + 1, 3) }}">
                                    <input type="radio" name="plan_id" value="{{ $plan->id }}"
                                           @checked((int) old('plan_id') === (int) $plan->id) class="sr-only">
                                    <span class="bmos-plan-pick-acento"></span>

                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="text-base font-bold text-slate-800">{{ $plan->name }}</span>
                                        <span class="bmos-plan-pick-check" aria-hidden="true">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="h-3 w-3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                            </svg>
                                        </span>
                                    </span>

                                    <span class="mt-1 flex items-baseline gap-1">
                                        <span class="text-xs font-semibold text-slate-400">RD$</span>
                                        <span class="bmos-plan-pick-precio">{{ number_format((float) $plan->price, 0) }}</span>
                                        <span class="text-xs text-slate-400">/ {{ mb_strtolower($plan->billing_cycle->label()) }}</span>
                                    </span>

                                    @if ($plan->description)
                                        <span class="mt-1 block text-xs leading-snug text-slate-500">{{ $plan->description }}</span>
                                    @endif

                                    <span class="mt-3 block border-t border-slate-100 pt-2.5">
                                        @if ($plan->modules === null)
                                            <span class="text-xs font-semibold text-indigo-600">Todos los módulos</span>
                                        @else
                                            <span class="flex flex-wrap gap-1">
                                                @foreach (array_slice($plan->moduleKeys(), 0, 4) as $clave)
                                                    <span class="bmos-plan-pick-modulo">{{ \App\Modules\Core\Support\ModuleRegistry::label($clave) }}</span>
                                                @endforeach
                                                @if (count($plan->moduleKeys()) > 4)
                                                    <span class="bmos-plan-pick-modulo">+{{ count($plan->moduleKeys()) - 4 }}</span>
                                                @endif
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
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
