@php
    use App\Modules\Core\Support\ModuleRegistry;
@endphp
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Planes y precios · BM Business OS</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bmos-auth-body">
    <div class="bmos-auth">
        @php
            $logoPath = public_path('images/bm-logo.png');
            $hasLogo = file_exists($logoPath);
        @endphp

        <div class="mx-auto w-full max-w-6xl py-2">
            {{-- Cabecera --}}
            <div class="text-center">
                <a href="{{ route('login') }}" class="inline-block">
                    @if ($hasLogo)
                        <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                             alt="BM Business OS" class="mx-auto h-14 object-contain">
                    @else
                        <span class="bmos-auth-logo">BM</span>
                    @endif
                </a>
                <h1 class="mt-5 text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    Elige el plan de tu negocio
                </h1>
                <p class="mx-auto mt-3 max-w-xl text-sm text-slate-300 sm:text-base">
                    @if ($planActual)
                        Cambia de plan cuando quieras. {{ $enPrueba
                            ? 'Durante la prueba el cambio es inmediato y no cuesta nada.'
                            : 'El cambio se aplica al confirmarse el pago.' }}
                    @else
                        Prueba {{ $trialDays }} días sin costo y sin tarjeta. Empiezas en el plan Básico
                        y lo cambias cuando quieras desde tu panel.
                    @endif
                </p>
            </div>

            @if ($plans->isEmpty())
                <div class="mx-auto mt-10 max-w-md rounded-2xl bg-white p-6 text-center">
                    <p class="font-semibold text-slate-700">No hay planes disponibles ahora mismo.</p>
                    <p class="mt-1 text-sm text-slate-500">Escríbenos y te damos de alta a mano.</p>
                </div>
            @else
                <div class="mt-10 grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($plans as $plan)
                        @php
                            $esActual = $planActual === $plan->id;
                            // «Recomendado» solo para quien aún no tiene plan: a un cliente que ya
                            // está dentro no se le recomienda el de entrada, se le enseña el suyo.
                            $recomendado = ! $planActual && $plan->slug === $planDeEntrada;
                            $nivel = min($loop->index + 1, 3);
                        @endphp

                        <div class="bmos-pricing bmos-pricing--nivel{{ $nivel }}
                                    {{ $recomendado ? 'bmos-pricing--destacado' : '' }}
                                    {{ $esActual ? 'bmos-pricing--actual' : '' }}">
                            <span class="bmos-pricing-acento"></span>

                            <div class="flex items-start justify-between gap-2">
                                <p class="text-xl font-bold tracking-tight text-slate-800">{{ $plan->name }}</p>
                                @if ($esActual)
                                    <span class="bmos-badge badge-green shrink-0">Tu plan</span>
                                @elseif ($recomendado)
                                    <span class="bmos-pricing-cinta shrink-0">Para empezar</span>
                                @endif
                            </div>

                            @if ($plan->description)
                                <p class="mt-1 text-sm leading-snug text-slate-500">{{ $plan->description }}</p>
                            @endif

                            <div class="mt-5 flex items-baseline gap-1.5">
                                <span class="text-sm font-semibold text-slate-400">RD$</span>
                                <span class="bmos-pricing-precio">{{ number_format((float) $plan->price, 0) }}</span>
                                <span class="text-sm font-medium text-slate-400">/ {{ mb_strtolower($plan->billing_cycle->label()) }}</span>
                            </div>
                            <p class="mt-1 text-xs font-medium text-emerald-600">
                                {{ $trialDays }} días de prueba gratis, sin tarjeta
                            </p>

                            {{-- Características: salen de los módulos que el plan concede de verdad,
                                 así que no pueden prometer algo que el cliente luego no reciba. --}}
                            <div class="mt-5 flex-1 border-t border-slate-100 pt-4">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Incluye</p>
                                <div class="mt-1">
                                    @foreach ($plan->moduleKeys() as $clave)
                                        <div class="bmos-pricing-item">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                            </svg>
                                            <span>
                                                <b class="font-semibold text-slate-700">{{ ModuleRegistry::label($clave) }}</b>
                                                @if ($frase = ModuleRegistry::description($clave))
                                                    — {{ $frase }}
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($plan->modules === null)
                                    <p class="mt-2 text-xs font-semibold text-indigo-600">
                                        Y cualquier módulo que se añada en el futuro.
                                    </p>
                                @endif

                                @if ($plan->max_users)
                                    <p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600">
                                        Hasta <b>{{ $plan->max_users }}</b> {{ $plan->max_users === 1 ? 'usuario' : 'usuarios' }}
                                    </p>
                                @endif
                            </div>

                            {{-- El botón depende de quién mira; la decisión viene del controlador. --}}
                            <div class="mt-5">
                                @if ($esActual)
                                    <span class="bmos-pricing-actual">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                        </svg>
                                        Es tu plan actual
                                    </span>
                                @elseif (! auth()->check())
                                    <a href="{{ route('register.form') }}"
                                       class="bmos-pricing-btn {{ $recomendado ? '' : 'bmos-pricing-btn--suave' }}">
                                        Empezar gratis
                                    </a>
                                @elseif ($puedeContratar)
                                    {{-- En prueba el cambio es gratis e inmediato; pagando, pasa por
                                         la pasarela. Las dos rutas lo vuelven a comprobar en el
                                         servidor: el botón es la puerta, no la cerradura. --}}
                                    <form method="POST"
                                          action="{{ $enPrueba
                                              ? route('panel.account.plan', $plan)
                                              : route('panel.account.checkout', $plan) }}">
                                        @csrf
                                        <button type="submit" class="bmos-pricing-btn">
                                            {{ $enPrueba ? 'Probar este plan' : 'Contratar' }}
                                        </button>
                                    </form>
                                    @if ($enPrueba)
                                        <p class="mt-1.5 text-center text-xs text-slate-400">
                                            Sin costo. Tu prueba mantiene la misma fecha de fin.
                                        </p>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Pie --}}
            <div class="mt-10 text-center">
                @auth
                    <a href="{{ route('panel.account') }}" class="text-sm font-semibold text-indigo-300 hover:text-white hover:underline">
                        ← Volver a mi suscripción
                    </a>
                @else
                    <p class="text-sm text-slate-400">
                        ¿Ya tienes cuenta?
                        <a href="{{ route('login') }}" class="font-semibold text-indigo-300 hover:text-white hover:underline">Inicia sesión</a>
                    </p>
                @endauth
                <p class="mt-4 text-xs text-slate-500">
                    Precios en pesos dominicanos, impuestos incluidos.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
