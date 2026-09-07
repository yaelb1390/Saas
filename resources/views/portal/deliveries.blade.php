@use('App\Modules\Delivery\Enums\DeliveryOutcomeReason')
@use('App\Modules\Delivery\Enums\DeliveryStatus')
@use('App\Modules\Delivery\Support\ComoLlegar')

{{--
    Las entregas del repartidor, en su móvil.

    LA REGLA QUE MANDA SOBRE TODO LO DEMÁS: el repartidor NO cobra. Trabaja para una empresa de
    logística; el dinero del pedido lo gestiona el comercio, de principio a fin. Por eso aquí no hay
    un solo importe: ni lo que lleva encima, ni lo que vale el pedido, ni lo que habría que cobrar.
    Antes lo primero que se veía era «Llevas cobrado y sin entregar en caja RD$610», que además de no
    ser asunto suyo lo hacía responsable de un dinero que nunca debió llevar. Marcar el cobro y
    liquidar es cosa de la oficina, y esa pantalla ya existe.

    Su trabajo es una cadena de seis pasos: recibir la entrega, recoger, transportar, llegar,
    entregar y confirmar. Todo lo que se ve aquí sirve a uno de esos seis.

    Está pensada para el peor contexto de todo el sistema: de pie en la calle, con una mano, con
    prisa y con el sol dando en la pantalla. De ahí las decisiones que parecen exageradas:

      · Una tarjeta por entrega y nada de tablas: a 390 px una tabla obliga a desplazar en horizontal
        con el pulgar mientras se sujeta un paquete.
      · La DIRECCIÓN es lo más grande. Es lo único que necesita mirar mientras conduce.
      · LA REFERENCIA va destacada y con rótulo: «casa azul, al lado del colmado» es lo que de verdad
        encuentra la puerta en este país. En letra chica era esconder el dato más útil.
      · Botones de 3rem: con guantes, con lluvia o con el móvil en la otra mano, uno de tamaño de
        escritorio se falla.
--}}
<x-layouts.app title="Mis entregas">
    <x-slot:header>
        <div class="mx-auto flex max-w-xl items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-gray-900">Mis entregas</h1>
                @if ($employee)
                    <p class="entrega-quien">
                        <span class="truncate">{{ $employee->name }}</span>
                        {{-- El estado se DEDUCE de sus entregas, no lo declara él: un interruptor que
                             se olvida de tocar lo deja «disponible» mientras reparte, y en el local
                             le echan encima otra parada creyéndolo libre. --}}
                        <span class="entrega-estado" data-estado="{{ $estadoDelRepartidor }}">
                            @switch ($estadoDelRepartidor)
                                @case('en_entrega') En entrega @break
                                @case('fuera') Fuera de servicio @break
                                @default Disponible
                            @endswitch
                        </span>
                    </p>
                @endif
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="shrink-0 text-sm font-medium text-gray-500 hover:text-gray-700">Salir</button>
            </form>
        </div>
    </x-slot:header>

    {{-- El ancho se contiene AQUÍ y no en el layout, que lo comparten más pantallas. --}}
    <div class="entregas">
        @if ($employee === null)
            {{-- Pasa de verdad: se crea la cuenta y se olvida el vínculo con la ficha. Sin este aviso
                 el repartidor ve una pantalla vacía y concluye que no tiene entregas. --}}
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <p class="font-semibold text-amber-900">Tu usuario no está vinculado a tu ficha de empleado.</p>
                <p class="mt-1 text-sm text-amber-800">
                    Hasta que tu encargado lo arregle no podrás ver tus entregas. Enséñale esta pantalla:
                    se hace en <b>Usuarios</b>, editando tu cuenta y eligiéndote en «¿Es uno de tus empleados?».
                </p>
            </div>
        @else
            {{-- EL DÍA, EN CUATRO NÚMEROS. Cuenta entregas, nunca dinero. --}}
            <div class="entrega-resumen">
                <div class="entrega-dato"><span class="entrega-dato-cifra">{{ $resumen['pendientes'] }}</span>Pendientes</div>
                <div class="entrega-dato" data-tono="ruta"><span class="entrega-dato-cifra">{{ $resumen['enRuta'] }}</span>En ruta</div>
                <div class="entrega-dato" data-tono="hecho"><span class="entrega-dato-cifra">{{ $resumen['entregadas'] }}</span>Entregadas</div>
                <div class="entrega-dato" data-tono="aviso"><span class="entrega-dato-cifra">{{ $resumen['incidencias'] }}</span>Incidencias</div>
            </div>

            @php
                $abiertas = collect($deliveries)->filter(fn ($d) => ! $d->status->isFinal());
                $cerradas = collect($deliveries)->filter(fn ($d) => $d->status->isFinal());
            @endphp

            {{--
                LA RUTA DEL DÍA.

                Va PLEGADA y en la misma pantalla, no en otra ruta: sirve para hacerse una idea del
                día de un vistazo —cuántas paradas quedan y por dónde— antes de arrancar, y luego
                estorba. Mandarlo a otra pantalla obligaría a salir y volver de la lista de entregas,
                que es donde de verdad trabaja.

                El orden es el de ASIGNACIÓN, no por cercanía. Ordenar por distancia exige que todas
                tengan punto —y casi ninguna lo tiene todavía, el sistema los está aprendiendo— y un
                cálculo que hoy no existe. Un orden inventado a medias es peor que uno predecible.
            --}}
            @if ($abiertas->isNotEmpty())
                <div x-data="{ abierta: false }" class="entrega-ruta">
                    <button type="button" @click="abierta = !abierta" :aria-expanded="abierta ? 'true' : 'false'"
                            class="entrega-ruta-cab">
                        <x-icono name="mapa" />
                        <span>Ver ruta del día</span>
                        <span class="entrega-ruta-cuenta">{{ $abiertas->count() }}</span>
                    </button>

                    <ol x-show="abierta" x-cloak class="entrega-ruta-lista">
                        @foreach ($abiertas as $parada)
                            @php $irParada = ComoLlegar::para($parada); @endphp
                            <li class="entrega-parada">
                                <span class="entrega-parada-num">{{ $loop->iteration }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="entrega-parada-quien">Orden {{ $parada->code }} · {{ $parada->paraQuien() }}</span>
                                    <span class="entrega-parada-donde">{{ $parada->address }}</span>
                                </span>
                                <span class="bmos-badge shrink-0 {{ $parada->status->badge() }}">{{ $parada->status->label() }}</span>
                                <a href="{{ $irParada->googleMaps() }}" target="_blank" rel="noopener"
                                   class="entrega-parada-ir" aria-label="Navegar a esta parada">
                                    <x-icono name="navegar" />
                                </a>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif

            @if ($abiertas->isEmpty())
                <div class="entrega-vacio">
                    <x-icono name="truck" />
                    <p>No tienes entregas pendientes.</p>
                </div>
            @endif

            <div class="space-y-4">
                @foreach ($abiertas as $d)
                    @php
                        $ir = ComoLlegar::para($d);
                        $lineas = $d->sale?->items ?? collect();
                        $enRuta = $d->status === DeliveryStatus::InTransit;
                    @endphp

                    <article x-data="{ cerrando: null, ubicando: false }"
                             data-estado="{{ $d->status->value }}" class="entrega">
                        <div class="entrega-cab">
                            <span class="entrega-orden">Orden {{ $d->code }}</span>
                            <span class="bmos-badge {{ $d->status->badge() }}">{{ $d->status->label() }}</span>
                        </div>

                        {{-- A dónde hay que ir: lo más grande de la tarjeta. --}}
                        <p class="entrega-direccion">{{ $d->address }}</p>
                        <p class="entrega-cliente"><x-icono name="users" />{{ $d->paraQuien() }}</p>

                        @if ($d->notes)
                            <p class="entrega-sena">
                                <span class="entrega-sena-rotulo">Referencia</span>
                                {{ $d->notes }}
                            </p>
                        @endif

                        {{--
                            LLAMAR · WAZE · MAPS, los tres iguales. Cuál se usa depende de si la
                            dirección se entiende o hay que preguntar. Los enlaces salen de
                            `ComoLlegar`, que usa el punto exacto si esta entrega o la ficha del
                            cliente ya lo tienen, y si no la dirección escrita.
                        --}}
                        <div class="entrega-acciones">
                            @if ($d->phone)
                                <a href="tel:{{ $d->phone }}" class="entrega-accion">
                                    <x-icono name="telefono" />
                                    Llamar
                                </a>
                            @endif

                            <a href="{{ $ir->waze() }}" target="_blank" rel="noopener" class="entrega-accion">
                                <x-icono name="navegar" />
                                Waze
                            </a>

                            <a href="{{ $ir->googleMaps() }}" target="_blank" rel="noopener" class="entrega-accion">
                                <x-icono name="mapa" />
                                Maps
                            </a>
                        </div>

                        {{--
                            GUARDAR LA UBICACIÓN. Discreto: es mantenimiento, no reparto.

                            La primera vez se llega preguntando, y al llegar se marca la puerta. La
                            próxima entrega a este cliente nace con el punto exacto. Solo se escribe
                            cuando él lo pulsa: es la puerta del cliente, no un rastro del motorista.
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
                                    <span x-show="!ubicando" class="inline-flex items-center gap-1.5">
                                        <x-icono name="ubicacion" />
                                        {{ $d->latitude !== null ? 'Ubicación guardada · volver a marcar' : 'Guardar esta ubicación' }}
                                    </span>
                                    <span x-show="ubicando" x-cloak>Buscando dónde estás…</span>
                                </button>
                            </form>
                        @endif

                        {{--
                            LA FOTO DE LA ENTREGA.

                            Existe sobre todo para PROTEGER AL REPARTIDOR. Cuando un cliente llama
                            diciendo que no le entregaron nada, sin foto es su palabra contra la del
                            cliente, y en esa discusión el que no tiene con qué defenderse es él.

                            `capture="environment"` abre la cámara trasera directamente, sin pasar por
                            el carrete: son dos toques menos de pie en una puerta. Y se envía sola al
                            elegir la foto, porque un botón de «subir» aparte se olvida — la foto queda
                            elegida y nunca llega.

                            No tiene nada que ver con el pago: él no cobra. Solo confirma que llegó.
                        --}}
                        @if ($puedeSubirEvidencia)
                            <form method="POST" action="{{ route('portal.deliveries.evidence', $d) }}"
                                  enctype="multipart/form-data" x-ref="foto">
                                @csrf
                                <label class="entrega-foto {{ $d->evidence_path !== null ? 'is-guardada' : '' }}">
                                    <input type="file" name="evidence" accept="image/*" capture="environment"
                                           class="sr-only" @change="$refs.foto.submit()">
                                    <x-icono name="foto" />
                                    <span>
                                        @if ($d->evidence_path !== null)
                                            ✓ Foto tomada · repetirla
                                        @else
                                            Tomar foto de la entrega
                                        @endif
                                    </span>
                                </label>
                            </form>
                        @endif


                        {{--
                            QUÉ LLEVA. Solo lo que hace falta para entregarlo: cuántos y cuáles.

                            SIN PRECIOS, y no por olvido: el repartidor no cobra, así que un importe
                            aquí no le sirve para nada y sí lo pone en el sitio incómodo de saber lo
                            que vale lo que lleva encima.
                        --}}
                        @if ($lineas->isNotEmpty())
                            <div class="entrega-pedido">
                                <p class="entrega-pedido-cab">
                                    <x-icono name="bag" />
                                    {{ $lineas->count() }} {{ $lineas->count() === 1 ? 'producto' : 'productos' }}
                                    @if ($comercio)
                                        <span class="entrega-comercio">{{ $comercio->name }}</span>
                                    @endif
                                </p>
                                <ul class="entrega-lista">
                                    @foreach ($lineas as $linea)
                                        <li>
                                            <span class="entrega-cant">{{ (float) $linea->quantity }}</span>
                                            {{ $linea->product?->name ?? 'Producto' }}
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{--
                            LA CADENA DE ESTADOS: asignada → en ruta → entregada.

                            Se ofrece UN solo paso cada vez, el siguiente. Una pantalla con todos los
                            botones a la vez obliga a pensar cuál toca, y esto se usa conduciendo.
                        --}}
                        <div x-show="cerrando === null">
                            @unless ($enRuta)
                                <form method="POST" action="{{ route('portal.deliveries.start', $d) }}">
                                    @csrf
                                    <button type="submit" class="entrega-principal" data-tono="ruta">
                                        <x-icono name="truck" stroke-width="2" />
                                        <span>Iniciar entrega</span>
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('portal.deliveries.close', $d) }}" id="entregar-{{ $d->id }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="{{ DeliveryOutcomeReason::Delivered->value }}">
                                    {{-- NO se envía `collected`: confirma que ENTREGÓ, no que cobró. --}}
                                    <button type="button" class="entrega-principal"
                                            @click="window.confirmarAccion({
                                                titulo: 'Confirmar entrega',
                                                mensaje: @js('Orden '.$d->code.' · '.$d->paraQuien().' · '.$d->address),
                                                aviso: '¿Confirmas que entregaste el pedido?',
                                                confirmar: 'Sí, la entregué',
                                                tono: 'seguro',
                                                formulario: 'entregar-{{ $d->id }}',
                                            })">
                                        <x-icono name="check" stroke-width="2.2" />
                                        <span>Confirmar entrega</span>
                                    </button>
                                </form>
                            @endunless

                            <div class="entrega-secundarias">
                                <button type="button" @click="cerrando = 'failed'" class="entrega-suave">
                                    <x-icono name="alert" />
                                    No pude entregar
                                </button>
                                <button type="button" @click="cerrando = 'cancelled'" class="entrega-suave">
                                    <x-icono name="ban" />
                                    Cancelar
                                </button>
                            </div>
                        </div>

                        {{-- Segundo paso: el motivo. Botones y no un desplegable: elegir de una lista
                             desplegable en un móvil son dos toques y una lista que tapa la pantalla. --}}
                        @foreach ([DeliveryStatus::Failed, DeliveryStatus::Cancelled] as $salida)
                            <div x-show="cerrando === '{{ $salida->value }}'" x-cloak class="mt-4">
                                <p class="mb-2 text-sm font-semibold text-gray-700">
                                    {{ $salida === DeliveryStatus::Failed ? '¿Por qué no pudiste entregar?' : '¿Por qué se cancela?' }}
                                </p>
                                <form method="POST" action="{{ route('portal.deliveries.close', $d) }}" class="space-y-2">
                                    @csrf
                                    @foreach (DeliveryOutcomeReason::para($salida) as $motivo)
                                        <button type="submit" name="reason" value="{{ $motivo->value }}" class="entrega-motivo">
                                            {{ $motivo->label() }}
                                        </button>
                                    @endforeach
                                    <input type="text" name="note" placeholder="Observación (opcional)"
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
                {{-- Lo cerrado HOY. Sin esto, pulsar el botón equivocado hace desaparecer la entrega
                     y no hay forma de darse cuenta hasta que llama el cliente. --}}
                <div class="mt-8">
                    <p class="entrega-seccion"><x-icono name="reloj" /> Entregas completadas hoy</p>
                    <div class="space-y-2">
                        @foreach ($cerradas as $d)
                            <div class="entrega-cerrada">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-800">
                                        Orden {{ $d->code }} · {{ $d->paraQuien() }}
                                    </p>
                                    <p class="truncate text-xs text-gray-500">
                                        {{ $d->address }} · {{ $d->outcome_reason?->label() ?? $d->status->label() }}
                                        @if ($d->delivered_at)
                                            · {{ $d->delivered_at->format('g:i A') }}
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
