@use('App\Modules\Delivery\Enums\DeliveryOutcomeReason')
@use('App\Modules\Delivery\Enums\DeliveryStatus')

{{--
    Las entregas del repartidor, en su móvil.

    Está pensada para el peor contexto de todo el sistema: de pie en la calle, con una mano, con
    prisa y con el sol dando en la pantalla. De ahí las decisiones que aquí parecen exageradas:

      · Una tarjeta por entrega y nada de tablas: una tabla a 390 px obliga a desplazar en horizontal
        con el pulgar mientras se sujeta una funda de comida.
      · La DIRECCIÓN es lo más grande de la tarjeta. Es lo único que necesita mirar mientras conduce.
      · El teléfono es un enlace `tel:`: llamar al cliente es lo primero que hace cuando no encuentra
        la casa, y copiar un número a mano en la calle no lo hace nadie.
      · Cerrar es un motivo, no un estado. Ver `DeliveryOutcomeReason`.
      · Botones de 3rem: con guantes, con lluvia o con el móvil en la otra mano, un botón de tamaño
        de escritorio se falla.
--}}
<x-layouts.app title="Mis entregas">
    <x-slot:header>
        <div class="flex items-center justify-between gap-3">
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
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm text-amber-800">Llevas cobrado y sin entregar en caja</p>
                <p class="text-2xl font-bold text-amber-900">{{ money($enLaCalle) }}</p>
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
                <div x-data="{ cerrando: null }" class="rounded-xl bg-white p-5 shadow">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            {{-- Lo primero y lo más grande: a dónde hay que ir. --}}
                            <p class="text-lg font-semibold leading-snug text-gray-900">{{ $d->address }}</p>
                            <p class="mt-1 text-sm text-gray-600">{{ $d->paraQuien() }}</p>
                            @if ($d->notes)
                                <p class="mt-1 text-sm text-gray-500">{{ $d->notes }}</p>
                            @endif
                        </div>
                        <span class="bmos-badge shrink-0 {{ $d->status->badge() }}">{{ $d->status->label() }}</span>
                    </div>

                    @if ($d->phone)
                        <a href="tel:{{ $d->phone }}"
                           class="mt-3 flex min-h-[3rem] items-center justify-center gap-2 rounded-xl bg-gray-100 px-4 font-medium text-gray-800 active:bg-gray-200">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width:1.15rem;height:1.15rem">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/>
                            </svg>
                            Llamar a {{ $d->phone }}
                        </a>
                    @endif

                    @if ($d->cobraEnLaPuerta())
                        <p class="mt-3 rounded-xl bg-emerald-50 px-4 py-3 text-center font-semibold text-emerald-800">
                            Cobrar {{ money($d->amount_to_collect) }}
                        </p>
                    @endif

                    {{-- Los tres cierres. El primero es el que ocurre nueve de cada diez veces, así
                         que es el único a todo lo ancho y en verde. --}}
                    <div class="mt-4 space-y-2" x-show="cerrando === null">
                        <form method="POST" action="{{ route('portal.deliveries.close', $d) }}">
                            @csrf
                            <input type="hidden" name="reason" value="{{ DeliveryOutcomeReason::Delivered->value }}">
                            @if ($d->cobraEnLaPuerta())
                                <input type="hidden" name="collected" value="1">
                            @endif
                            <button type="submit"
                                    class="flex min-h-[3.25rem] w-full items-center justify-center rounded-xl bg-emerald-600 px-4 text-base font-semibold text-white active:bg-emerald-700">
                                @if ($d->cobraEnLaPuerta())
                                    Entregada y cobré {{ money($d->amount_to_collect) }}
                                @else
                                    Entregada
                                @endif
                            </button>
                        </form>

                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" @click="cerrando = 'failed'"
                                    class="min-h-[3rem] rounded-xl border border-gray-300 px-3 text-sm font-medium text-gray-700 active:bg-gray-100">
                                No pude entregarla
                            </button>
                            <button type="button" @click="cerrando = 'cancelled'"
                                    class="min-h-[3rem] rounded-xl border border-gray-300 px-3 text-sm font-medium text-gray-700 active:bg-gray-100">
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
                                    <button type="submit" name="reason" value="{{ $motivo->value }}"
                                            class="flex min-h-[3rem] w-full items-center rounded-xl border border-gray-300 px-4 text-left text-sm font-medium text-gray-800 active:bg-gray-100">
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
                </div>
            @endforeach
        </div>

        @if ($cerradas->isNotEmpty())
            {{-- Lo cerrado HOY. Sin esto, pulsar el botón equivocado hace desaparecer la entrega y no
                 hay forma de darse cuenta hasta que llama el cliente. --}}
            <div class="mt-8">
                <p class="mb-3 text-sm font-semibold text-gray-500">Cerradas hoy</p>
                <div class="space-y-2">
                    @foreach ($cerradas as $d)
                        <div class="flex items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 shadow-sm">
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

    @include('partials.toast')
</x-layouts.app>
