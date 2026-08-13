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
            {{-- Cabecera compacta: en un portátil, cada línea de aquí es una característica menos
                 que se ve sin desplazar. --}}
            <div class="text-center">
                <a href="{{ route('login') }}" class="inline-block">
                    @if ($hasLogo)
                        <img src="{{ asset('images/bm-logo.png') }}?v={{ filemtime($logoPath) }}"
                             alt="BM Business OS" class="mx-auto h-10 object-contain">
                    @else
                        <span class="bmos-auth-logo">BM</span>
                    @endif
                </a>
                <h1 class="mt-3 text-2xl font-bold tracking-tight text-white sm:text-3xl">
                    Elige el plan de tu negocio
                </h1>
                <p class="mx-auto mt-2 max-w-xl text-sm text-slate-300">
                    @if ($planActual)
                        Cambia cuando quieras. {{ $enPrueba
                            ? 'Durante la prueba el cambio es inmediato y no cuesta nada.'
                            : 'El cambio se aplica al confirmarse el pago.' }}
                    @else
                        {{ $trialDays }} días gratis, sin tarjeta. Empiezas en Básico y lo cambias desde tu panel.
                    @endif
                </p>
            </div>

            @if ($plans->isEmpty())
                <div class="mx-auto mt-10 max-w-md rounded-2xl bg-white p-6 text-center">
                    <p class="font-semibold text-slate-700">No hay planes disponibles ahora mismo.</p>
                    <p class="mt-1 text-sm text-slate-500">Escríbenos y te damos de alta a mano.</p>
                </div>
            @else
                {{-- `items-stretch` y el botón empujado abajo: las tarjetas miden lo mismo y sus
                     botones quedan alineados aunque una liste más cosas. --}}
                <div class="mt-6 grid grid-cols-1 items-stretch gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($plans as $plan)
                        @php
                            $esActual = $planActual === $plan->id;
                            $recomendado = ! $planActual && $plan->slug === $planDeEntrada;
                            $nivel = min($loop->index + 1, 3);

                            /*
                             * Cada plan enseña SOLO lo que añade al anterior.
                             *
                             * Listar los catorce módulos con su explicación hacía que la tarjeta más
                             * completa midiera el triple que las demás y no cupiera en pantalla. Y
                             * quien compara no necesita leer tres veces lo mismo: necesita ver qué
                             * gana al subir de plan.
                             *
                             * La frase «todo lo del plan X» solo se dice si es CIERTA: se comprueba
                             * que el anterior esté contenido de verdad. Si alguien configura planes
                             * que no encajan, se cae a la lista completa en lugar de prometer algo
                             * que no se cumple.
                             */
                            $anterior = $loop->index > 0 ? $plans[$loop->index - 1] : null;
                            $contieneAlAnterior = $anterior !== null
                                && $anterior->moduleKeys() !== []
                                && array_diff($anterior->moduleKeys(), $plan->moduleKeys()) === [];

                            $aMostrar = $contieneAlAnterior
                                ? array_values(array_diff($plan->moduleKeys(), $anterior->moduleKeys()))
                                : $plan->moduleKeys();

                            // Tope para que ninguna tarjeta se dispare de alto.
                            $tope = 7;
                            $resto = max(0, count($aMostrar) - $tope);
                            $visibles = array_slice($aMostrar, 0, $tope);
                        @endphp

                        <div class="bmos-pricing bmos-pricing--nivel{{ $nivel }} {{ $recomendado ? 'bmos-pricing--destacado' : '' }} {{ $esActual ? 'bmos-pricing--actual' : '' }}">
                            <span class="bmos-pricing-acento"></span>

                            <div class="flex items-start justify-between gap-2">
                                <p class="text-lg font-bold tracking-tight text-slate-800">{{ $plan->name }}</p>
                                @if ($esActual)
                                    <span class="bmos-badge badge-green shrink-0">Tu plan</span>
                                @elseif ($recomendado)
                                    <span class="bmos-pricing-cinta shrink-0">Para empezar</span>
                                @endif
                            </div>

                            <div class="mt-3 flex items-baseline gap-1.5">
                                <span class="text-sm font-semibold text-slate-400">RD$</span>
                                <span class="bmos-pricing-precio">{{ number_format((float) $plan->price, 0) }}</span>
                                <span class="text-sm font-medium text-slate-400">/ {{ mb_strtolower($plan->billing_cycle->label()) }}</span>
                            </div>
                            <p class="mt-0.5 text-xs font-medium text-emerald-600">
                                {{ $trialDays }} días gratis, sin tarjeta
                            </p>

                            <div class="mt-4 flex-1 border-t border-slate-100 pt-3">
                                @if ($contieneAlAnterior)
                                    <p class="mb-2 text-sm font-semibold text-slate-700">
                                        Todo lo de <span class="text-indigo-600">{{ $anterior->name }}</span>, y además:
                                    </p>
                                @else
                                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Incluye</p>
                                @endif

                                @foreach ($visibles as $clave)
                                    <div class="bmos-pricing-item">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="h-4 w-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                                        </svg>
                                        <span>
                                            <b class="font-semibold text-slate-700">{{ ModuleRegistry::label($clave) }}</b>
                                            @if ($frase = ModuleRegistry::description($clave))
                                                <span class="text-slate-500">— {{ $frase }}</span>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach

                                @if ($resto > 0)
                                    <p class="mt-1.5 pl-6 text-sm font-semibold text-indigo-600">
                                        y {{ $resto }} módulo{{ $resto === 1 ? '' : 's' }} más
                                    </p>
                                @endif

                                @if ($plan->modules === null)
                                    <p class="mt-2 text-xs font-semibold text-indigo-600">
                                        Y cualquier módulo que se añada en el futuro.
                                    </p>
                                @endif

                                @if ($plan->max_users)
                                    <p class="mt-3 text-sm text-slate-500">
                                        Hasta <b class="text-slate-700">{{ $plan->max_users }}</b> {{ $plan->max_users === 1 ? 'usuario' : 'usuarios' }}
                                    </p>
                                @endif
                            </div>

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
            <div class="mt-7 text-center">
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
                <p class="mt-3 text-xs text-slate-500">
                    Precios en pesos dominicanos, impuestos incluidos.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
