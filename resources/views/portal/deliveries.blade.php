@use('App\Modules\Delivery\Enums\DeliveryOutcomeReason')
@use('App\Modules\Delivery\Enums\DeliveryStatus')
@use('App\Modules\Delivery\Support\ComoLlegar')

{{--
    Las entregas del repartidor, en su móvil.

    Está pensada para el peor contexto de todo el sistema: de pie en la calle, con una mano, con
    prisa y con el sol dando en la pantalla. De ahí las decisiones que aquí parecen exageradas:

      · Una tarjeta por entrega y nada de tablas: una tabla a 390 px obliga a desplazar en horizontal
        con el pulgar mientras se sujeta una funda de comida.
      · La DIRECCIÓN es lo más grande de la tarjeta. Es lo único que necesita mirar mientras conduce.
      · LA SEÑA va destacada y con rótulo, no en letra chica. «Callejón blanco» o «al lado del
        colmado» es lo que de verdad encuentra la casa en este país; enterrarlo en gris pequeño era
        esconder el dato más útil que tiene la tarjeta.
      · Llamar, Waze y Google Maps a la misma altura y del tamaño del pulgar: cuál se usa depende de
        si la dirección se entiende o hay que preguntar.
      · Cerrar es un motivo, no un estado. Ver `DeliveryOutcomeReason`.
      · Botones de 3rem: con guantes, con lluvia o con el móvil en la otra mano, un botón de tamaño
        de escritorio se falla.
--}}
<x-layouts.app title="Mis entregas">
    <x-slot:header>
        <div class="mx-auto flex max-w-xl items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-gray-900">Mis entregas</h1>
                @if ($employee)
                    <p class="truncate text-sm text-gray-500">{{ $employee->name }}</p>
                @endif
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="shrink-0 text-sm font-medium text-gray-500 hover:text-gray-700">Salir</button>
            </form>
        </div>
    </x-slot:header>

    {{-- El ancho se contiene AQUÍ y no en el layout, que lo comparten más pantallas. En el móvil no
         se nota; en un escritorio deja de haber una tarjeta de 1.200 px con tres líneas dentro. --}}
    <div class="entregas">
        @if ($employee === null)
            {{-- Pasa de verdad: se crea la cuenta y se olvida el vínculo con la ficha. Sin este aviso el
                 repartidor ve una pantalla vacía y concluye que no tiene entregas. --}}
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <p class="font-semibold text-amber-900">Tu usuario no está vinculado a tu ficha de empleado.</p>
                <p class="mt-1 text-sm text-amber-800">
                    Hasta que tu encargado lo arregle no podrás ver tus entregas. Enséñale esta pantalla:
                    se hace en <b>Usuarios</b>, editando tu cuenta y eligiéndote en «¿Es uno de tus empleados?».
                </p>
            </div>
        @else
            @if (bccomp((string) $enLaCalle, '0', 2) > 0)
                {{-- Lo que lleva encima. Va arriba porque es lo que le van a preguntar al llegar al local,
                     y porque saber que lleva RD$3,000 en el bolsillo cambia cómo conduce. --}}
                <div class="entrega-encima mb-5">
                    <p class="text-sm text-amber-800">Llevas cobrado y sin entregar en caja</p>
                    <p class="entrega-encima-cifra">{{ money($enLaCalle) }}</p>
                </div>
            @endif

            @php
                $abiertas = collect($deliveries)->filter(fn ($d) => ! $d->status->isFinal());
                $cerradas = collect($deliveries)->filter(fn ($d) => $d->status->isFinal());
            @endphp

            @if ($abiertas->isEmpty())
                <div class="rounded-xl bg-white p-6 text-center shadow">
                    <p class="text-gray-500">No tienes entregas pendientes.</p>
                </div>
            @endif

            <div class="space-y-4">
                @foreach ($abiertas as $d)
                    @php $ir = ComoLlegar::para($d); @endphp

                    <article x-data="{ cerrando: null, ubicando: false }"
                             data-estado="{{ $d->status->value }}" class="entrega">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                {{-- Lo primero y lo más grande: a dónde hay que ir. --}}
                                <p class="entrega-direccion">{{ $d->address }}</p>
                                <p class="entrega-cliente">{{ $d->paraQuien() }}</p>
                            </div>
                            <span class="bmos-badge shrink-0 {{ $d->status->badge() }}">{{ $d->status->label() }}</span>
                        </div>

                        @if ($d->notes)
                            <p class="entrega-sena">
                                <span class="entrega-sena-rotulo">Cómo llegar</span>
                                {{ $d->notes }}
                            </p>
                        @endif

                        {{--
                            LLAMAR · WAZE · MAPS.

                            Los tres iguales: ninguno manda sobre los otros. Si la dirección se
                            entiende se navega; si no —que en este país es lo normal— se llama. Los
                            enlaces salen de `ComoLlegar`, que usa el punto exacto si esta entrega o
                            la ficha del cliente ya lo tienen, y si no la dirección escrita.
                        --}}
                        <div class="entrega-acciones">
                            @if ($d->phone)
                                <a href="tel:{{ $d->phone }}" class="entrega-accion">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/>
                                    </svg>
                                    Llamar
                                </a>
                            @endif

                            <a href="{{ $ir->waze() }}" target="_blank" rel="noopener" class="entrega-accion">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/>
                                </svg>
                                Waze
                            </a>

                            <a href="{{ $ir->googleMaps() }}" target="_blank" rel="noopener" class="entrega-accion">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>
                                </svg>
                                Maps
                            </a>
                        </div>

                        {{--
                            GUARDAR LA UBICACIÓN. Discreto a propósito: es mantenimiento, no reparto,
                            y no debe competir con «Entregada».

                            Aquí está el porqué de toda esta pantalla: la primera vez se llega
                            preguntando, y al llegar se marca la puerta. La próxima entrega a este
                            cliente nace con el punto exacto y ya nadie vuelve a llamar por el
                            callejón blanco.

                            Solo se escribe cuando él lo pulsa: nunca al abrir la pantalla, nunca en
                            segundo plano. Esto es la puerta del cliente, no un rastro del motorista.
                        --}}
                        @if ($puedeGuardarUbicacion)
                            <form method="POST" action="{{ route('portal.deliveries.location', $d) }}" x-ref="ubicacion">
                                @csrf
                                <input type="hidden" name="latitude" x-ref="lat">
                                <input type="hidden" name="longitude" x-ref="lng">
                                <button type="button"
                                        class="entrega-guardar {{ $d->latitude !== null ? 'is-guardada' : '' }}"
                                        :disabled="ubicando"
                                        @click="
                                            if (! navigator.geolocation) {
                                                window.avisoFlash?.('Tu teléfono no permite compartir la ubicación.', 'error');
                                                return;
                                            }
                                            ubicando = true;
                                            navigator.geolocation.getCurrentPosition(
                                                (p) => {
                                                    $refs.lat.value = p.coords.latitude;
                                                    $refs.lng.value = p.coords.longitude;
                                                    $refs.ubicacion.submit();
                                                },
                                                () => {
                                                    ubicando = false;
                                                    window.avisoFlash?.('No se pudo leer tu ubicación. Sal a cielo abierto e inténtalo otra vez.', 'error');
                                                },
                                                { enableHighAccuracy: true, timeout: 15000 }
                                            );
                                        ">
                                    <span x-show="!ubicando">
                                        @if ($d->latitude !== null)
                                            ✓ Ubicación guardada · volver a marcar
                                        @else
                                            Guardar esta ubicación
                                        @endif
                                    </span>
                                    <span x-show="ubicando" x-cloak>Buscando dónde estás…</span>
                                </button>
                            </form>
                        @endif

                        @php $cobra = $d->cobraEnLaPuerta(); @endphp

                        {{-- Los tres cierres. El primero es el que ocurre nueve de cada diez veces, así
                             que es el único a todo lo ancho y en verde. El importe va SOLO aquí: antes
                             salía también como aviso arriba, y dos veces el mismo número hace dudar de
                             si son dos cobros. --}}
                        <div x-show="cerrando === null">
                            <form method="POST" action="{{ route('portal.deliveries.close', $d) }}">
                                @csrf
                                <input type="hidden" name="reason" value="{{ DeliveryOutcomeReason::Delivered->value }}">
                                @if ($cobra)
                                    <input type="hidden" name="collected" value="1">
                                @endif
                                <button type="submit" class="entrega-principal">
                                    @if ($cobra)
                                        <span>Entregada y cobré</span>
                                        <span class="entrega-principal-importe">{{ money($d->amount_to_collect) }}</span>
                                    @else
                                        <span>Entregada</span>
                                    @endif
                                </button>
                            </form>

                            <div class="entrega-secundarias">
                                <button type="button" @click="cerrando = 'failed'" class="entrega-suave">
                                    No pude entregarla
                                </button>
                                <button type="button" @click="cerrando = 'cancelled'" class="entrega-suave">
                                    Cancelada
                                </button>
                            </div>
                        </div>

                        {{-- Segundo paso: el motivo. Botones y no un desplegable: elegir de una lista
                             desplegable en un móvil son dos toques y una lista que tapa la pantalla. --}}
                        @foreach ([DeliveryStatus::Failed, DeliveryStatus::Cancelled] as $salida)
                            <div x-show="cerrando === '{{ $salida->value }}'" x-cloak class="mt-4">
                                <p class="mb-2 text-sm font-semibold text-gray-700">
                                    {{ $salida === DeliveryStatus::Failed ? '¿Qué pasó?' : '¿Por qué se cancela?' }}
                                </p>
                                <form method="POST" action="{{ route('portal.deliveries.close', $d) }}" class="space-y-2">
                                    @csrf
                                    @foreach (DeliveryOutcomeReason::para($salida) as $motivo)
                                        <button type="submit" name="reason" value="{{ $motivo->value }}" class="entrega-motivo">
                                            {{ $motivo->label() }}
                                        </button>
                                    @endforeach
                                    <input type="text" name="note" placeholder="Añadir algo (opcional)"
                                           class="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm">
                                </form>
                                <button type="button" @click="cerrando = null"
                                        class="mt-2 min-h-[2.75rem] w-full text-sm font-medium text-gray-500">
                                    Volver
                                </button>
                            </div>
                        @endforeach
                    </article>
                @endforeach
            </div>

            @if ($cerradas->isNotEmpty())
                {{-- Lo cerrado HOY. Sin esto, pulsar el botón equivocado hace desaparecer la entrega y no
                     hay forma de darse cuenta hasta que llama el cliente. --}}
                <div class="mt-8">
                    <p class="mb-3 text-sm font-semibold text-gray-500">Cerradas hoy</p>
                    <div class="space-y-2">
                        @foreach ($cerradas as $d)
                            <div class="entrega-cerrada">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-800">{{ $d->address }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $d->outcome_reason?->label() ?? $d->status->label() }}
                                        @if ($d->pendienteDeLiquidar())
                                            · <span class="font-semibold text-amber-700">{{ money($d->amount_to_collect) }} encima</span>
                                        @endif
                                    </p>
                                </div>
                                <span class="bmos-badge shrink-0 {{ $d->status->badge() }}">{{ $d->status->label() }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>

    @include('partials.toast')
</x-layouts.app>
