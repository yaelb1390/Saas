<x-layouts.admin title="Patio" heading="Patio de vehículos" subheading="Cada unidad con su chasis, lo que costó y a cuánto se vende">
    <div>
        @if ($faltaMigrar)
            {{--
                El código llega antes que la migración: aquí se aplican a mano y el despliegue no las
                corre. En ese hueco la pantalla explica qué falta en vez de devolver un 500, que es lo
                que ya tumbó Redes sociales en producción.
            --}}
            <div class="bmos-card bmos-card-pad text-center">
                <p class="text-lg font-semibold text-slate-700">Falta preparar la base de datos</p>
                <p class="mt-1 text-sm text-slate-500">
                    El módulo de vehículos está instalado, pero sus tablas todavía no.
                    Avisa a quien administre el sistema para que aplique las migraciones pendientes.
                </p>
            </div>
        @else
            {{--
                El resumen se pinta en el servidor, ANTES del JavaScript: si la rejilla tardara o
                fallara, la pantalla sigue diciendo cuántos carros hay.

                Con icono y tono, como las tarjetas de Préstamos: los estilos ya estaban escritos en
                el panel y esta pantalla no los usaba, y por eso se veía más pobre que sus vecinas.
            --}}
            <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="bmos-stat bmos-stat-patio">
                    <div class="bmos-stat-icon tone-indigo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                    </div>
                    <p class="bmos-stat-label">En el patio</p>
                    <p class="bmos-stat-value">{{ $resumen['total'] }}</p>
                    <p class="bmos-stat-pie">unidades registradas</p>
                </div>

                <div class="bmos-stat bmos-stat-patio">
                    <div class="bmos-stat-icon tone-emerald">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Disponibles</p>
                    <p class="bmos-stat-value text-emerald-600">{{ $resumen['disponibles'] }}</p>
                    <p class="bmos-stat-pie">listas para vender</p>
                </div>

                <div class="bmos-stat bmos-stat-patio">
                    <div class="bmos-stat-icon tone-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Apartados</p>
                    <p class="bmos-stat-value text-amber-600">{{ $resumen['apartados'] }}</p>
                    <p class="bmos-stat-pie">con inicial entregado</p>
                </div>

                <div class="bmos-stat bmos-stat-patio">
                    <div class="bmos-stat-icon tone-sky">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797-2.101c.727-.198 1.453.164 1.453.925V19.5a2.25 2.25 0 0 1-2.25 2.25H2.25V18.75Zm0 0a2.25 2.25 0 0 0 2.25 2.25h.75m-3-2.25V6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-.75m-9-6a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Vendidos</p>
                    <p class="bmos-stat-value text-sky-600">{{ $resumen['vendidos'] }}</p>
                    <p class="bmos-stat-pie">ya entregados</p>
                </div>
            </div>

            {{--
                Los dos gráficos del patio.

                Con Chart.js, que ya está instalada y se descarga BAJO DEMANDA: no se le añade ni un
                byte a las pantallas que no dibujan nada.

                Compactos y en una sola tarjeta a propósito: la tabla es lo que se viene a mirar, y
                dos gráficos altos la empujarían fuera de la pantalla.
            --}}
            @if ($resumen['total'] > 0)
                <div class="bmos-card bmos-card-pad mb-5"
                     x-data="graficosDelPatio({
                        estados: @js([
                            'Disponibles' => $resumen['disponibles'],
                            'Apartados' => $resumen['apartados'],
                            'Vendidos' => $resumen['vendidos'],
                            'Retirados' => $resumen['total'] - $resumen['disponibles'] - $resumen['apartados'] - $resumen['vendidos'],
                        ]),
                        marcas: @js($porMarca),
                     })"
                     x-init="dibujar()">
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div>
                            <p class="bmos-gtitulo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-indigo-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z"/>
                                </svg>
                                Cómo está el patio
                            </p>
                            <div class="bmos-anillo">
                                <canvas x-ref="estados"></canvas>
                                {{-- El total va en el agujero: es la cifra que se busca al mirar un
                                     reparto, y así no hay que sumar la leyenda de cabeza. --}}
                                <div class="bmos-anillo-centro">
                                    <b>{{ $resumen['total'] }}</b>
                                    <span>{{ $resumen['total'] === 1 ? 'unidad' : 'unidades' }}</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            <p class="bmos-gtitulo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-indigo-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
                                </svg>
                                Unidades por marca
                            </p>
                            <div class="h-44"><canvas x-ref="marcas"></canvas></div>
                        </div>
                    </div>
                </div>
            @endif

            <div x-data="patioDeVehiculos({ puedeGestionar: {{ $puedeGestionar ? 'true' : 'false' }} })" x-init="arrancar()">

                {{-- ---------------------------------------------------------- Buscar y filtrar --}}
                <div class="bmos-card bmos-card-pad mb-5">
                    <p class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-700">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4 text-indigo-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                        </svg>
                        Buscar vehículos
                    </p>

                    <div class="mb-3">
                        <input type="search" x-model="texto" @input.debounce.300ms="recargar()"
                               @keydown.enter.prevent="recargar()"
                               placeholder="Chasis, marca, modelo, placa o color…"
                               class="bmos-input">
                    </div>

                    {{-- Los filtros con su etiqueta encima. Sin ella hay que abrir el desplegable
                         para saber qué filtra, que es justo lo que pasaba antes. --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="bmos-field-label">Marca</label>
                            <select x-model="marca" @change="recargar()" class="bmos-input">
                                <option value="">Todas las marcas</option>
                                @foreach ($marcas as $marca)
                                    <option value="{{ $marca }}">{{ $marca }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="bmos-field-label">Año</label>
                            <select x-model="anio" @change="recargar()" class="bmos-input">
                                <option value="">Todos los años</option>
                                @foreach ($anios as $anio)
                                    <option value="{{ $anio }}">{{ $anio }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="bmos-field-label">Estado</label>
                            <select x-model="estado" @change="recargar()" class="bmos-input">
                                <option value="">Todos los estados</option>
                                @foreach ($estados as $unEstado)
                                    <option value="{{ $unEstado->value }}">{{ $unEstado->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="flex items-end">
                            {{--
                                Con contorno propio y no con `bmos-btn-ghost`, que es texto a secas.

                                La primera versión llevaba borde fino y fondo blanco —los mismos que
                                `.bmos-input`— y al final de una hilera de desplegables se leía como
                                UN CAMPO DE TEXTO VACÍO, no como un botón. Ahora lleva fondo gris,
                                icono y el texto en negrita.
                            --}}
                            <button type="button" @click="limpiar()" class="bmos-btn-limpiar"
                                    x-bind:disabled="!hayFiltros()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                </svg>
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                {{-- ---------------------------------------------------------------- La rejilla --}}
                <div class="bmos-card">
                    {{--
                        La barra de la tabla.

                        El rótulo y el conteo van APILADOS, no en fila: puestos uno al lado del otro
                        competían por la misma línea y el conteo se leía como parte del título. Con
                        el icono en su cuadro de color, el bloque se identifica de un vistazo aunque
                        la página vaya scrolleada.
                    --}}
                    <div class="bmos-tabla-barra">
                        <span class="bmos-tabla-icono">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>
                            </svg>
                        </span>

                        <div class="min-w-0">
                            <p class="bmos-tabla-titulo">Inventario de vehículos</p>
                            <p class="bmos-tabla-conteo" x-text="cuantas"></p>
                        </div>

                        <div class="ms-auto flex flex-wrap items-center gap-2">
                            {{--
                                Tabla o galería.

                                Las dos leen LOS MISMOS datos ya descargados: cambiar de vista no
                                pide nada al servidor. La galería es como se mira un patio de
                                verdad —por la foto— y la tabla es para comparar cifras.
                            --}}
                            <div class="bmos-conmutador">
                                <button type="button" data-vista="tabla" @click="vista = 'tabla'"
                                        :class="vista === 'tabla' && 'is-activa'" title="Ver como tabla">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                                </button>
                                <button type="button" data-vista="galeria" @click="vista = 'galeria'"
                                        :class="vista === 'galeria' && 'is-activa'" title="Ver con fotos">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                                </button>
                            </div>

                            {{-- Exportar a CSV es de la edición gratuita. El de Excel es de pago y no
                                 se usa: sin licencia pinta una marca de agua sobre la tabla. --}}
                            <button type="button" @click="exportar()" class="bmos-btn bmos-btn-ghost">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                                </svg>
                                Exportar CSV
                            </button>

                            @can('vehicles.manage')
                                <x-panel.create-modal title="Registrar vehículo" label="Agregar vehículo"
                                                      form="vehiculo" width="max-w-2xl" enctype="multipart/form-data"
                                                      action="{{ route('panel.vehicles.store') }}">
                                    {{-- Solo marca y modelo son obligatorios: un carro llega y hay que
                                         anotarlo YA. Pedir la ficha completa hace que se apunte en un
                                         papel, y el papel no está en el sistema. --}}
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <x-panel.field name="make" label="Marca" required placeholder="Toyota" />
                                        <x-panel.field name="model" label="Modelo" required placeholder="Corolla" />
                                        <x-panel.field name="year" label="Año" type="number" placeholder="2019" />
                                        <x-panel.field name="trim" label="Versión" placeholder="LE, XLE…" />
                                        <x-panel.field name="vin" label="Chasis (VIN)" placeholder="1HGCM82633A004352" />
                                        <x-panel.field name="plate" label="Placa" />
                                        <x-panel.field name="color" label="Color" />
                                        <x-panel.field name="mileage" label="Kilometraje" type="number" />

                                        <div>
                                            <label class="bmos-field-label">Combustible</label>
                                            <select name="fuel" class="bmos-input">
                                                <option value="">—</option>
                                                <option value="Gasolina">Gasolina</option>
                                                <option value="Gasoil">Gasoil</option>
                                                <option value="Gas">Gas</option>
                                                <option value="Híbrido">Híbrido</option>
                                                <option value="Eléctrico">Eléctrico</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="bmos-field-label">Transmisión</label>
                                            <select name="transmission" class="bmos-input">
                                                <option value="">—</option>
                                                <option value="Automática">Automática</option>
                                                <option value="Manual">Manual</option>
                                            </select>
                                        </div>

                                        <x-panel.field name="purchase_cost" label="Costo de compra" type="number" step="0.01" />
                                        <x-panel.field name="asking_price" label="Precio de venta" type="number" step="0.01" />
                                        <x-panel.field name="acquired_at" label="Fecha de entrada" type="date" />

                                        @if ($sucursales->count() > 1)
                                            <div>
                                                <label class="bmos-field-label">Sucursal</label>
                                                <select name="branch_id" class="bmos-input">
                                                    <option value="">—</option>
                                                    @foreach ($sucursales as $sucursal)
                                                        <option value="{{ $sucursal->id }}">{{ $sucursal->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="mt-4">
                                        <x-panel.zona-archivos
                                            name="photo" label="Foto" accept="image/*" unidad="foto" genero="f"
                                            hint="Una por unidad. Se recorta sola a formato horizontal." />
                                    </div>

                                    <div class="mt-4">
                                        <label class="bmos-field-label">Notas</label>
                                        <textarea name="notes" rows="2" class="bmos-input"></textarea>
                                    </div>
                                </x-panel.create-modal>
                            @endcan
                        </div>
                    </div>

                    {{--
                        La rejilla.

                        Hace su PROPIO desplazamiento horizontal, así que por muchas columnas que
                        tenga nunca empuja la página a lo ancho, que es como se rompen estas pantallas
                        en un teléfono.
                    --}}
                    <div x-show="cargando" class="p-12 text-center text-sm text-slate-400">Cargando el patio…</div>

                    <div x-show="fallo" x-cloak class="p-12 text-center">
                        <p class="text-sm text-slate-600" x-text="fallo"></p>
                        <button type="button" @click="arrancar()" class="bmos-btn bmos-btn-ghost mt-3">Reintentar</button>
                    </div>

                    {{-- La rejilla se OCULTA, no se destruye, al pasar a galería: volver a crearla
                         cada vez perdería el orden y los filtros que el usuario tuviera puestos. --}}
                    {{--
                        La rejilla va dentro de un envoltorio que RECORTA.

                        `.bmos-card` redondea sus esquinas pero no recorta su contenido, y la rejilla
                        es un bloque de esquinas rectas que llega hasta el borde: por abajo asomaba
                        fuera del marco. El recorte se le pone AQUÍ y no a `.bmos-card`, porque
                        recortar todas las tarjetas del panel cortaría desplegables y menús en las
                        otras once pantallas.
                    --}}
                    <div x-show="!cargando && !fallo && vista === 'tabla'" class="bmos-rejilla-marco">
                        <div x-ref="rejilla" class="bmos-rejilla"></div>
                    </div>

                    {{-- La galería: la misma información, mirada por la foto. --}}
                    <div x-show="!cargando && !fallo && vista === 'galeria'" x-cloak class="p-4">
                        <p x-show="filas.length === 0" class="py-10 text-center text-sm text-slate-400">
                            No hay vehículos que mostrar.
                        </p>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            <template x-for="f in filas" :key="f.id">
                                <button type="button" @click="abrirFicha(f)" class="bmos-tarjeta-vehiculo">
                                    <div class="bmos-tarjeta-foto">
                                        <template x-if="f.foto">
                                            <img :src="f.foto" alt="" loading="lazy">
                                        </template>
                                        <template x-if="!f.foto">
                                            <span class="bmos-tarjeta-sinfoto"></span>
                                        </template>
                                        <span class="bmos-badge bmos-tarjeta-estado"
                                              :class="tonoDe(f.estado)" x-text="f.estado_texto"></span>
                                    </div>

                                    <div class="p-3">
                                        <p class="truncate text-sm font-semibold text-slate-800"
                                           x-text="f.marca + ' ' + f.modelo"></p>
                                        <p class="text-xs text-slate-400">
                                            <span x-text="f.anio || 'año sin anotar'"></span>
                                            <span x-show="f.km"> · <span x-text="Number(f.km).toLocaleString('es-DO')"></span> km</span>
                                        </p>
                                        <p class="mt-2 text-base font-semibold text-indigo-600" x-text="pesos(f.precio)"></p>
                                        <p x-show="f.cliente" class="mt-1 truncate text-xs text-slate-500" x-text="f.cliente"></p>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- ------------------------------------------------------------- Ficha lateral --}}
                {{--
                    La ficha, ANCHA y centrada.

                    Antes era un cajón lateral de 448 px con la foto arriba: en ese ancho, una foto de
                    carro —que se guarda apaisada 4:3— salía diminuta, y los datos quedaban en una
                    columna larguísima que había que recorrer entera.

                    Ahora ocupa el centro en dos columnas: la foto grande a la izquierda con su
                    galería debajo, y a la derecha TODO lo de la unidad. En un teléfono las dos
                    columnas se apilan solas.
                --}}
                <div x-show="ficha" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-3 sm:p-6"
                     @keydown.escape.window="ficha = null">
                    <div @click.outside="ficha = null" x-transition
                         class="max-h-full w-full max-w-5xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
                        <template x-if="ficha">
                            <div>
                                <div class="flex items-start justify-between gap-3 border-b border-slate-100 p-5">
                                    <div>
                                        <p class="text-lg font-semibold text-slate-800"
                                           x-text="ficha.marca + ' ' + ficha.modelo + ' ' + (ficha.anio || '')"></p>
                                        <p class="text-sm text-slate-400" x-text="ficha.codigo"></p>
                                    </div>
                                    <button type="button" @click="ficha = null" class="text-slate-400 hover:text-slate-600">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 gap-6 p-5 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)]">
                                    {{-- ------------------------------------------------ La foto --}}
                                    <div>
                                        {{--
                                            Lienzo apaisado 4:3, el mismo en que se recuadran las fotos
                                            al subirlas. Fijo y no según la imagen: con proporción libre,
                                            cada unidad abriría la ficha de un alto distinto y el panel
                                            daría un salto al cambiar de carro.
                                        --}}
                                        <div class="bmos-ficha-foto">
                                            <template x-if="fotoGrande()">
                                                <img :src="fotoGrande()" alt="" x-transition.opacity>
                                            </template>
                                            <template x-if="!fotoGrande()">
                                                <span class="bmos-ficha-sinfoto"></span>
                                            </template>

                                            <span class="bmos-badge bmos-ficha-estado"
                                                  :class="tonoDe(ficha.estado)" x-text="ficha.estado_texto"></span>
                                        </div>

                                        {{-- La galería debajo: se pulsa una y pasa a verse grande.
                                             No cambia la principal —eso se hace en su pestaña—, solo
                                             lo que se está mirando. --}}
                                        <div x-show="detalle && detalle.fotos.length > 1" class="mt-3 flex flex-wrap gap-2">
                                            <template x-for="f in (detalle ? detalle.fotos : [])" :key="f.id">
                                                <button type="button" @click="verFoto = f.url"
                                                        class="bmos-ficha-mini" :class="fotoGrande() === f.url && 'es-activa'">
                                                    <img :src="f.url" alt="" loading="lazy">
                                                </button>
                                            </template>
                                        </div>

                                        <div class="mt-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            <span class="text-2xl font-bold text-slate-800" x-text="pesos(ficha.precio)"></span>
                                            <span x-show="ficha.dias !== null" class="text-xs text-slate-400">
                                                lleva <span x-text="ficha.dias"></span> días en el patio
                                            </span>
                                        </div>
                                    </div>

                                    {{-- ----------------------------------------------- Los datos --}}
                                    <div>
                                    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                        <template x-for="d in detalles()" :key="d[0]">
                                            <div>
                                                <dt class="text-xs text-slate-400" x-text="d[0]"></dt>
                                                <dd class="text-slate-700" x-text="d[1]"></dd>
                                            </div>
                                        </template>
                                    </dl>

                                    {{-- El dinero: SOLO si el servidor lo mandó. La comprobación no es
                                         estética, es que sin permiso esas claves ni existen. --}}
                                    <template x-if="'costo' in ficha">
                                        <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Cuentas</p>
                                            <div class="space-y-1.5 text-sm">
                                                <div class="flex justify-between">
                                                    <span class="text-slate-500">Compra</span>
                                                    <span class="text-slate-700" x-text="pesos(ficha.costo)"></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-slate-500">Preparación</span>
                                                    <span class="text-slate-700" x-text="pesos(ficha.gastos)"></span>
                                                </div>
                                                <div class="flex justify-between border-t border-slate-200 pt-1.5">
                                                    <span class="font-medium text-slate-600">Costo real</span>
                                                    <span class="font-medium text-slate-800" x-text="pesos(ficha.costo_real)"></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="font-medium text-slate-600">Margen</span>
                                                    <span class="font-semibold"
                                                          :class="ficha.margen < 0 ? 'text-rose-600' : 'text-emerald-600'"
                                                          x-text="pesos(ficha.margen)"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="detalle && detalle.trato">
                                        <div class="mt-5 rounded-xl border border-slate-200 p-4">
                                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Trato</p>
                                            <p class="text-sm text-slate-700">
                                                <span x-text="detalle.trato.codigo"></span> ·
                                                <span x-text="detalle.trato.cliente"></span> ·
                                                <span x-text="detalle.trato.estado"></span>
                                            </p>
                                            <p class="mt-1 text-sm text-slate-500">
                                                Falta por cobrar <span class="font-medium" x-text="pesos(detalle.trato.saldo)"></span>
                                            </p>
                                        </div>
                                    </template>

                                    {{--
                                        Pestañas.

                                        Con todo en una columna, la galería y el historial dejaban la
                                        ficha con tres pantallas de desplazamiento y el precio, el
                                        estado y las cuentas fuera de la vista. Lo que se mira siempre
                                        se queda arriba, fijo; lo que se consulta a ratos va por
                                        pestañas.
                                    --}}
                                    <div class="mt-5 flex gap-1 border-b border-slate-100">
                                        <template x-for="p in pestanasFicha()" :key="p[0]">
                                            <button type="button" @click="pestana = p[0]"
                                                    class="bmos-ficha-pestana" :class="pestana === p[0] && 'is-activa'">
                                                <span x-text="p[1]"></span>
                                                <span x-show="p[2] > 0" class="bmos-ficha-num" x-text="p[2]"></span>
                                            </button>
                                        </template>
                                    </div>

                                    {{-- ---------------------------------------------------- Fotos --}}
                                    <div x-show="pestana === 'fotos'" class="mt-4">
                                        <p x-show="!detalle || detalle.fotos.length === 0" class="py-6 text-center text-sm text-slate-400">
                                            Esta unidad no tiene fotos todavía.
                                        </p>

                                        <div class="grid grid-cols-3 gap-2">
                                            <template x-for="f in (detalle ? detalle.fotos : [])" :key="f.id">
                                                <div class="bmos-galeria-foto" :class="f.principal && 'es-principal'">
                                                    <img :src="f.url" alt="" loading="lazy">

                                                    @can('vehicles.manage')
                                                        <div class="bmos-galeria-acciones">
                                                            {{-- Los formularios se pintan en el
                                                                 SERVIDOR, con su token: el
                                                                 JavaScript nunca fabrica un CSRF. --}}
                                                            <form method="POST" x-bind:action="rutaFoto(f.id, 'principal')" x-show="!f.principal">
                                                                @csrf
                                                                <button class="bmos-galeria-btn" title="Poner como principal">★</button>
                                                            </form>
                                                            {{--
                                                                La confirmación va por
                                                                `window.confirmarAccion` y NO por el
                                                                `confirm` del navegador: aquel trata
                                                                igual archivar un cliente que destruir
                                                                un plan, y el panel tiene un test que
                                                                prohíbe usarlo en las vistas.

                                                                `@submit.prevent` porque el diálogo
                                                                es asíncrono: sin frenar el envío, el
                                                                formulario se mandaría antes de que
                                                                nadie contestara.
                                                            --}}
                                                            <form method="POST" x-bind:action="rutaFoto(f.id, '')"
                                                                  @submit.prevent="borrarFoto($el)">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button class="bmos-galeria-btn" title="Eliminar">✕</button>
                                                            </form>
                                                        </div>
                                                    @endcan

                                                    <span x-show="f.principal" class="bmos-galeria-marca">Principal</span>
                                                </div>
                                            </template>
                                        </div>

                                        @can('vehicles.manage')
                                            <form method="POST" x-bind:action="'{{ url('panel/vehiculos') }}/' + ficha.id + '/fotos'"
                                                  enctype="multipart/form-data" class="mt-4">
                                                @csrf
                                                <x-panel.zona-archivos
                                                    name="photos[]" label="Añadir fotos"
                                                    accept="image/*" multiple unidad="foto" genero="f"
                                                    hint="Se recortan solas a formato horizontal. Máximo 20 por unidad." />

                                                <button class="bmos-btn bmos-btn-primary mt-3">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" class="h-4 w-4">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                                    </svg>
                                                    Subir
                                                </button>
                                            </form>
                                        @endcan
                                    </div>

                                    {{-- ----------------------------------------------- Documentos --}}
                                    @can('vehicles.manage')
                                        <div x-show="pestana === 'documentos'" class="mt-4">
                                            <p x-show="documentos.length === 0" class="py-6 text-center text-sm text-slate-400">
                                                Sin papeles guardados.
                                            </p>

                                            <template x-for="d in documentos" :key="d.id">
                                                <div class="flex items-start justify-between gap-3 border-b border-slate-100 py-2.5 text-sm last:border-0">
                                                    <div class="min-w-0">
                                                        <span class="bmos-badge" :class="d.tono" x-text="d.tipo"></span>
                                                        <a :href="d.url" target="_blank" rel="noopener"
                                                           class="mt-1 block truncate text-slate-700 hover:text-indigo-600" x-text="d.nombre"></a>
                                                        <p class="text-xs text-slate-400">
                                                            <span x-text="d.tamano"></span> · <span x-text="d.fecha"></span>
                                                        </p>
                                                    </div>
                                                    <form method="POST" x-bind:action="rutaDoc(d.id)"
                                                          @submit.prevent="borrarDocumento($el, d.nombre)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button class="bmos-accion" title="Eliminar">✕</button>
                                                    </form>
                                                </div>
                                            </template>

                                            <form method="POST" x-bind:action="'{{ url('panel/vehiculos') }}/' + ficha.id + '/documentos'"
                                                  enctype="multipart/form-data" class="mt-4 space-y-3">
                                                @csrf
                                                <div>
                                                    <label class="bmos-field-label">Qué documento es</label>
                                                    <select name="type" class="bmos-input">
                                                        @foreach ($tiposDocumento as $tipo)
                                                            <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <x-panel.zona-archivos
                                                    name="document" label="Archivo" accept=".pdf,image/*"
                                                    hint="PDF o imagen, hasta 15 MB." />

                                                <button class="bmos-btn bmos-btn-primary">Guardar documento</button>
                                            </form>
                                        </div>
                                    @endcan

                                    {{-- --------------------------------------------------- Gastos --}}
                                    @can('vehicles.manage')
                                        <div x-show="pestana === 'gastos'" class="mt-4">
                                            <p x-show="!detalle || detalle.trabajos.length === 0" class="py-6 text-center text-sm text-slate-400">
                                                No se le ha gastado nada todavía.
                                            </p>

                                            <template x-for="(t, i) in (detalle ? detalle.trabajos : [])" :key="i">
                                                <div class="flex items-start justify-between gap-3 border-b border-slate-100 py-2 text-sm last:border-0">
                                                    <div>
                                                        <span class="bmos-badge" :class="t.tono" x-text="t.tipo"></span>
                                                        <p class="mt-1 text-slate-700" x-text="t.descripcion"></p>
                                                        <p class="text-xs text-slate-400">
                                                            <span x-text="t.quien || 'sin anotar quién'"></span>
                                                            <span x-show="t.fecha"> · <span x-text="t.fecha"></span></span>
                                                            · <span x-text="t.estado"></span>
                                                        </p>
                                                    </div>
                                                    <span class="shrink-0 text-slate-600" x-text="pesos(t.costo)"></span>
                                                </div>
                                            </template>
                                        </div>
                                    @endcan

                                    {{-- ------------------------------------------------ Historial --}}
                                    @can('vehicles.manage')
                                        <div x-show="pestana === 'historial'" class="mt-4">
                                            {{-- Sale de la AUDITORÍA, no de una tabla propia: el
                                                 sistema ya guarda quién cambió qué, cuándo y desde
                                                 dónde. --}}
                                            <p x-show="!detalle || detalle.historial.length === 0" class="py-6 text-center text-sm text-slate-400">
                                                No se le ha cambiado nada desde que se registró.
                                            </p>

                                            <template x-for="(h, i) in (detalle ? detalle.historial : [])" :key="i">
                                                <div class="border-b border-slate-100 py-2.5 text-sm last:border-0">
                                                    <p class="text-slate-700">
                                                        <span class="font-medium" x-text="h.campo"></span>:
                                                        <span class="text-slate-400" x-text="h.antes"></span>
                                                        <span class="text-slate-300">→</span>
                                                        <span x-text="h.despues"></span>
                                                    </p>
                                                    <p class="text-xs text-slate-400">
                                                        <span x-text="h.quien"></span> · <span x-text="h.cuando"></span>
                                                    </p>
                                                </div>
                                            </template>
                                        </div>
                                    @endcan

                                    {{--
                                        Las dos acciones de la ficha, separadas del contenido por una
                                        línea: sueltas debajo del historial parecían un párrafo más.

                                        «Anotar gasto» ya no va con `bmos-btn-ghost` —texto a secas—:
                                        al lado de un botón sólido no se leía como algo pulsable, el
                                        mismo problema que ya se arregló en «Limpiar filtros».
                                    --}}
                                    <div class="bmos-ficha-acciones">
                                        @can('vehicle_deals.manage')
                                            <a href="{{ route('panel.vehicle-deals') }}" class="bmos-btn bmos-btn-primary">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/>
                                                </svg>
                                                Apartar o vender
                                            </a>
                                        @endcan
                                        @can('vehicle_jobs.manage')
                                            <a :href="'{{ route('panel.vehicle-jobs') }}?vehiculo=' + ficha.id"
                                               class="bmos-btn bmos-btn-suave">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-4 w-4">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/>
                                                </svg>
                                                Anotar gasto
                                            </a>
                                        @endcan
                                    </div>
                                    </div>{{-- fin de la columna de datos --}}
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <script>
        /**
         * Los dos gráficos del resumen.
         *
         * Con Chart.js, que ya estaba instalada para el Dashboard y Reportes y se carga BAJO DEMANDA
         * con `window.loadChart()`: las pantallas que no dibujan nada no se la descargan.
         *
         * Si no llegara a cargar, los recuadros se quedan vacíos y ya está. Un gráfico es un extra:
         * la información que hace falta para trabajar está en las tarjetas y en la tabla, que se
         * pintan en el servidor.
         */
        function graficosDelPatio({ estados, marcas }) {
            return {
                async dibujar() {
                    let Chart;
                    try {
                        Chart = await window.loadChart();
                    } catch (e) {
                        return;
                    }

                    const etiquetas = Object.keys(estados).filter((k) => estados[k] > 0);

                    // Los mismos colores que las píldoras de estado de la tabla: si el anillo pintara
                    // «apartado» de otro color, habría que traducir mentalmente entre los dos.
                    const colores = {
                        Disponibles: '#059669',
                        Apartados: '#d97706',
                        Vendidos: '#0284c7',
                        Retirados: '#94a3b8',
                    };

                    new Chart(this.$refs.estados, {
                        type: 'doughnut',
                        data: {
                            labels: etiquetas,
                            datasets: [{
                                data: etiquetas.map((k) => estados[k]),
                                backgroundColor: etiquetas.map((k) => colores[k]),
                                borderWidth: 0,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            /*
                             * El agujero deja sitio al total, que se pinta encima en HTML.
                             *
                             * Se sube al 72 %: con el 62 % el anillo quedaba grueso y el número del
                             * centro no respiraba.
                             */
                            cutout: '72%',
                            plugins: {
                                legend: {
                                    // Abajo y no a un lado: a la derecha se quedaba pegada al borde
                                    // de la tarjeta con el anillo perdido en el centro.
                                    position: 'bottom',
                                    labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', padding: 14, font: { size: 12 } },
                                },
                            },
                        },
                    });

                    const nombres = Object.keys(marcas);

                    if (nombres.length === 0) return;

                    new Chart(this.$refs.marcas, {
                        type: 'bar',
                        data: {
                            labels: nombres,
                            datasets: [{
                                data: nombres.map((m) => marcas[m]),
                                backgroundColor: '#6366f1',
                                hoverBackgroundColor: '#4f46e5',
                                borderRadius: 6,
                                barThickness: 14,
                            }],
                        },
                        options: {
                            // Barras horizontales: los nombres de marca se leen enteros, y en
                            // vertical salían girados o cortados.
                            indexAxis: 'y',
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                // Sin decimales: son unidades, no puede haber media Toyota.
                                x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } },
                                y: { grid: { display: false } },
                            },
                        },
                    });
                },
            };
        }

        function patioDeVehiculos({ puedeGestionar }) {
            return {
                texto: '', marca: '', anio: '', estado: '',
                cuantas: '',
                cargando: true,
                fallo: '',
                api: null,
                vista: 'tabla',  // tabla o galería; las dos leen los mismos datos
                filas: [],       // lo último que trajo el servidor, para la galería
                temporizador: null,
                ficha: null,     // la fila que se está mirando
                verFoto: null,   // cuál de la galería se ve en grande
                detalle: null,   // gastos, fotos, historial y trato, pedidos aparte
                documentos: [],  // se piden solo al abrir su pestaña: llevan datos personales
                pestana: 'fotos',

                hayFiltros() {
                    return !!(this.texto || this.marca || this.anio || this.estado);
                },

                limpiar() {
                    this.texto = this.marca = this.anio = this.estado = '';
                    this.recargar();
                },

                pesos(n) {
                    if (n === null || n === undefined) return '';

                    return 'RD$ ' + Number(n).toLocaleString('es-DO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                // Los mismos tonos que las insignias del resto del panel: un estado no puede
                // significar una cosa aquí y otra en Ventas.
                tonoDe(estado) {
                    return {
                        available: 'badge-green',
                        reserved: 'badge-amber',
                        sold: 'badge-blue',
                        withdrawn: 'badge-gray',
                    }[estado] || 'badge-gray';
                },

                // Lo que no cabe en la tabla. Se omite lo que está vacío: una ficha llena de guiones
                // no informa, estorba.
                detalles() {
                    const f = this.ficha;
                    if (!f) return [];

                    return [
                        ['Chasis', f.vin],
                        ['Placa', f.placa],
                        ['Versión', f.version],
                        ['Color', f.color],
                        ['Kilometraje', f.km === null ? null : Number(f.km).toLocaleString('es-DO') + ' km'],
                        ['Transmisión', f.transmision],
                        ['Combustible', f.combustible],
                        ['Sucursal', f.sucursal],
                        ['Entró', f.ingreso],
                        ['Cliente', f.cliente],
                        ['Notas', f.notas],
                    ].filter((d) => d[1]);
                },

                /** Qué pestañas tiene sentido enseñar, con su contador. */
                pestanasFicha() {
                    const d = this.detalle;
                    const tabs = [['fotos', 'Fotos', d ? d.fotos.length : 0]];

                    if (puedeGestionar) {
                        tabs.push(
                            ['documentos', 'Documentos', d ? d.documentos : 0],
                            ['gastos', 'Gastos', d ? d.trabajos.length : 0],
                            ['historial', 'Historial', 0],
                        );
                    }

                    return tabs;
                },

                rutaFoto(id, accion) {
                    return '{{ url('panel/vehiculos') }}/' + this.ficha.id + '/fotos/' + id + (accion ? '/' + accion : '');
                },

                /**
                 * Pregunta y, si dicen que sí, envía el formulario que ya pintó el servidor.
                 *
                 * El formulario con su token y su método viene del servidor: el JavaScript solo lo
                 * envía. Nunca fabrica un CSRF, que es la misma regla que sigue el resto del panel.
                 */
                async borrarFoto(form) {
                    if (await window.confirmarAccion({
                        titulo: '¿Eliminar esta foto?',
                        mensaje: 'Se quita de la galería de esta unidad.',
                        confirmar: 'Eliminar',
                    })) {
                        form.submit();
                    }
                },

                async borrarDocumento(form, nombre) {
                    if (await window.confirmarAccion({
                        titulo: '¿Eliminar «' + nombre + '»?',
                        mensaje: 'El archivo se borra del sistema.',
                        aviso: 'No se puede deshacer.',
                        avisoGrave: true,
                        confirmar: 'Eliminar',
                    })) {
                        form.submit();
                    }
                },

                rutaDoc(id) {
                    return '{{ url('panel/vehiculos') }}/' + this.ficha.id + '/documentos/' + id;
                },

                /**
                 * La foto grande: la elegida de la galería, o la principal de la fila.
                 *
                 * Se resuelve aquí y no en la plantilla para que la miniatura activa y la imagen
                 * grande lean SIEMPRE lo mismo. Con la comprobación repetida en dos sitios, el día
                 * que cambie una se desincronizan y el recuadro azul marca una foto que no es la que
                 * se está viendo.
                 */
                fotoGrande() {
                    return this.verFoto || (this.ficha ? this.ficha.foto : null);
                },

                async abrirFicha(fila) {
                    this.ficha = fila;
                    // Se vuelve a la principal al abrir otra unidad: si no, quedaría seleccionada
                    // una foto del carro anterior.
                    this.verFoto = null;
                    this.detalle = null;
                    this.documentos = [];
                    this.pestana = 'fotos';

                    try {
                        const r = await fetch('/panel/vehiculos/' + fila.id + '/ficha', { headers: { Accept: 'application/json' } });
                        this.detalle = await r.json();

                        // Los papeles van por su propia ruta, que exige administrar: si el servidor
                        // los niega, la pestaña se queda vacía y no se rompe nada.
                        if (puedeGestionar) {
                            const rd = await fetch('/panel/vehiculos/' + fila.id + '/documentos', { headers: { Accept: 'application/json' } });
                            if (rd.ok) this.documentos = (await rd.json()).documentos || [];
                        }
                    } catch (e) {
                        // La ficha ya enseña lo esencial con lo que la rejilla tiene en memoria; si
                        // el detalle no llega, se queda sin taller pero no en blanco.
                        this.detalle = { trabajos: [], trato: null, fotos: [], documentos: 0, historial: [] };
                    }
                },

                async arrancar() {
                    this.cargando = true;
                    this.fallo = '';

                    let ag;
                    try {
                        // AG Grid llega en su propio trozo, descargado la primera vez que se abre
                        // esta pantalla. Si esa descarga falla —una conexión mala en el patio— se
                        // dice y se ofrece reintentar, en vez de dejar un recuadro vacío.
                        ag = await window.loadAgGrid();
                    } catch (e) {
                        this.cargando = false;
                        this.fallo = 'No se pudo cargar la tabla. Revisa la conexión.';

                        return;
                    }

                    const dinero = (p) => this.pesos(p.value);

                    /*
                     * El importe, con la moneda en tono menor.
                     *
                     * En una columna de cifras el «RD$» se repite en todas las filas y no aporta
                     * nada al comparar: puesto al mismo peso que el número, compite con él. Aquí
                     * queda como etiqueta gris y pequeña, y la vista cae directa en la cantidad.
                     *
                     * El `valueFormatter` se conserva porque es lo que usa la exportación a CSV;
                     * este renderizador solo cambia cómo se ve en pantalla.
                     */
                    const dineroCelda = (p) => {
                        if (p.value === null || p.value === undefined || p.value === '') return '';

                        const caja = document.createElement('span');
                        caja.className = 'bmos-importe';

                        const moneda = document.createElement('i');
                        moneda.textContent = 'RD$';

                        const cifra = document.createElement('b');
                        cifra.textContent = Number(p.value).toLocaleString('es-DO', {
                            minimumFractionDigits: 2, maximumFractionDigits: 2,
                        });

                        caja.append(moneda, cifra);

                        return caja;
                    };

                    /*
                     * Las columnas se CONSTRUYEN, no se declaran una vez.
                     *
                     * El ancho de la ventana decide cuáles caben legibles, y eso cambia cuando
                     * alguien maximiza la ventana o gira el teléfono. Como función, se pueden
                     * rehacer sin destruir la rejilla —y sin perder el orden ni los filtros que el
                     * usuario tuviera puestos—.
                     */
                    const construir = (ancho) => {
                    const columnas = [
                        {
                            headerName: 'Foto', field: 'foto', width: 112, sortable: false, filter: false,
                            cellClass: 'bmos-celda-foto',
                            // Se devuelve un nodo y no una cadena de HTML: así el navegador no tiene
                            // que interpretar texto que viene de la base de datos.
                            cellRenderer: (p) => {
                                const caja = document.createElement('div');
                                caja.className = 'bmos-miniatura';
                                if (p.value) {
                                    const img = document.createElement('img');
                                    img.src = p.value;
                                    img.loading = 'lazy';
                                    img.alt = '';
                                    caja.appendChild(img);
                                } else {
                                    caja.classList.add('is-vacia');
                                }

                                return caja;
                            },
                        },
                        { field: 'marca', headerName: 'Marca', flex: 1, minWidth: 95 },
                        { field: 'modelo', headerName: 'Modelo', flex: 1.1, minWidth: 100 },
                        { field: 'anio', headerName: 'Año', flex: 0.6, minWidth: 72, filter: 'agNumberColumnFilter' },
                        {
                            field: 'precio', headerName: 'Precio', flex: 1.3, minWidth: 135,
                            filter: 'agNumberColumnFilter',
                            /*
                             * SIN `type: 'rightAligned'`.
                             *
                             * Lo probé para alinear también la cabecera y salió peor: AG Grid empuja
                             * el rótulo a la derecha y el icono de filtro a la izquierda, y con estas
                             * anchuras «Precio» y «Estado» se montaban uno encima del otro y «Margen»
                             * quedaba cortado. Las celdas se alinean con su clase, que no toca la
                             * cabecera.
                             */
                            valueFormatter: dinero, cellRenderer: dineroCelda, cellClass: 'bmos-celda-dinero',
                        },
                        {
                            field: 'estado_texto', headerName: 'Estado', flex: 0.9, minWidth: 105, sortable: true,
                            // Centrada: el precio de al lado va pegado a la derecha, y sin esto la
                            // píldora quedaba tocándolo y las dos columnas se leían como una sola.
                            cellClass: 'bmos-celda-estado',
                            cellRenderer: (p) => {
                                const s = document.createElement('span');
                                // Con punto delante: en una columna de píldoras todas del mismo
                                // tamaño, el color de fondo solo se distingue comparándolas entre
                                // sí. El punto lo dice en la propia fila.
                                s.className = 'bmos-badge is-punto ' + this.tonoDe(p.data.estado);
                                s.textContent = p.value;

                                return s;
                            },
                        },
                    ];

                    /*
                     * QUÉ COLUMNAS CABEN, POR ORDEN DE LO QUE MENOS FALTA HACE.
                     *
                     * Los cortes NO son redondos: salen de medir. Cada columna tiene un ancho mínimo
                     * por debajo del cual no se lee, y el hueco útil es el ancho de la ventana menos
                     * el menú lateral y los márgenes. Se añade cada una cuando de verdad entra.
                     *
                     * Lo que se cae de la tabla NO se pierde: está entero en la ficha, a un clic del
                     * ojo. Es mejor eso que una tabla completa que obliga a arrastrar una barra.
                     *
                     * Los cortes subieron 50 px al modernizar la tabla: la foto pasó de 92 a 112
                     * —antes se montaba sobre el chasis— y el relleno de celda de 16 a 18. Son unos
                     * 45 px más de contenido, y con los cortes viejos a 1400 px reaparecía la barra
                     * horizontal que todo esto existe para evitar. Medido, no estimado.
                     */
                    if (ancho >= 1320) {
                        // El chasis es el primero en caerse: ocupa mucho, se busca desde arriba y
                        // para reconocer la unidad ya están la foto, la marca y el modelo.
                        columnas.splice(1, 0, {
                            field: 'vin', headerName: 'Chasis', flex: 1.3, minWidth: 100,
                            cellClass: 'bmos-celda-mono',
                        });
                    }

                    if (ancho >= 1150) {
                        // La antigüedad es de las primeras en caerse: es información para decidir,
                        // no para atender, y en pantalla pequeña se atiende.
                        columnas.push({
                            /*
                             * Cuánto lleva parada la unidad.
                             *
                             * Un carro tres meses en el patio es dinero quieto, y eso no se ve
                             * leyendo una fecha de entrada: hay que contar de cabeza. La barra lo
                             * dice de un vistazo y la columna ordena por el número, así que lo más
                             * viejo se pone arriba con un clic.
                             */
                            field: 'dias', headerName: 'En patio', flex: 0.8, minWidth: 92,
                            filter: 'agNumberColumnFilter',
                            cellClass: 'bmos-celda-antiguedad',
                            cellRenderer: (p) => {
                                const caja = document.createElement('div');
                                caja.className = 'bmos-antiguedad';

                                if (p.value === null || p.value === undefined) {
                                    caja.classList.add('is-desconocida');
                                    caja.title = 'No se anotó cuándo entró';

                                    return caja;
                                }

                                // Los cortes: hasta dos meses se considera normal; a partir de
                                // cuatro, la unidad lleva parada demasiado.
                                const tono = p.value > 120 ? 'es-vieja' : (p.value > 60 ? 'es-media' : 'es-nueva');
                                caja.classList.add(tono);

                                /*
                                 * El relleno va DENTRO de la píldora, de fondo, no como una barra
                                 * suelta debajo.
                                 *
                                 * Antes eran dos piezas separadas —número arriba, barra de 4 px
                                 * abajo— y a esa barra había que bajar la vista para leerla. Como
                                 * fondo de la propia píldora, el número y su proporción se leen en
                                 * el mismo golpe de vista y la celda deja de tener el hueco muerto
                                 * que separaba las dos piezas.
                                 */
                                const relleno = document.createElement('span');
                                relleno.className = 'bmos-antiguedad-relleno';
                                // Se topa al 100 %: a partir de medio año ya está lleno y lo que
                                // importa es el número.
                                relleno.style.width = Math.min(100, Math.round((p.value / 180) * 100)) + '%';

                                const txt = document.createElement('span');
                                txt.className = 'bmos-antiguedad-dias';
                                txt.textContent = p.value + ' d';

                                caja.appendChild(relleno);
                                caja.appendChild(txt);

                                return caja;
                            },
                        },);
                    }

                    if (ancho >= 1210) {
                        columnas.push({
                            field: 'cliente', headerName: 'Cliente', flex: 1, minWidth: 100,
                            valueFormatter: (p) => p.value || '—',
                        });
                    }

                    /*
                     * LAS COLUMNAS DE DINERO SOLO SI HAY SITIO.
                     *
                     * Con todas puestas, la tabla pedía 1637 px y en un portátil normal quedaban
                     * fuera «Costo real» y «Margen»: había que arrastrar la barra de abajo para ver
                     * justo las dos cifras por las que se abre esta pantalla.
                     *
                     * Encogerlas hasta que quepan no sirve —doce columnas en 958 px son 80 px cada
                     * una, ilegibles—, así que en pantalla estrecha se quitan de la tabla y se
                     * miran en la ficha, que las tiene enteras y con el desglose. En un monitor
                     * ancho salen las dos.
                     */
                    if (puedeGestionar && ancho >= 1565) {
                        columnas.push(
                            { field: 'costo_real', headerName: 'Costo real', flex: 1.1, minWidth: 120, valueFormatter: dinero, cellRenderer: dineroCelda, cellClass: 'bmos-celda-dinero' },
                        );
                    }

                    // El margen aguanta más: es LA cifra de la pantalla, así que se conserva hasta
                    // que de verdad no cabe.
                    if (puedeGestionar && ancho >= 1445) {
                        columnas.push({
                            field: 'margen', headerName: 'Margen', flex: 1.1, minWidth: 115,
                            valueFormatter: dinero, cellRenderer: dineroCelda, cellClass: 'bmos-celda-dinero',
                            // Un margen negativo es dinero perdido: tiene que saltar a la vista.
                            cellClassRules: { 'bmos-celda-perdida': (p) => Number(p.value) < 0 },
                        });
                    }

                    columnas.push({
                        // Sin agarradera de ancho: es una columna de un icono de 58 px fijos, no hay
                        // nada que ensanchar. Además, junto al borde de la columna fijada dibujaba
                        // una segunda rayita pegada a la suya y las dos juntas parecían un glifo.
                        headerName: '', field: 'id', width: 58, minWidth: 58, sortable: false, filter: false,
                        resizable: false, pinned: 'right',
                        cellClass: 'bmos-celda-acciones',
                        cellRenderer: (p) => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'bmos-accion';
                            b.title = 'Ver la ficha';
                            b.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>';
                            b.addEventListener('click', () => this.abrirFicha(p.data));

                            return b;
                        },
                    });

                    return columnas;
                    };

                    this.api = ag.createGrid(this.$refs.rejilla, {
                        columnDefs: construir(window.innerWidth),
                        defaultColDef: { sortable: true, filter: true, resizable: true },
                        rowData: [],
                        /*
                         * LAS COLUMNAS SE REPARTEN EL ANCHO CON `flex`, NO CON `autoSizeStrategy`.
                         *
                         * Probé `fitGridWidth` primero y NO encogió nada: se aplica al crear la
                         * rejilla, cuando todavía no había filas, y ya no vuelve a repartir. Se
                         * medía igual —1637 px de contenido en 1342 de hueco— con la barra de abajo
                         * puesta. Con `flex` el reparto lo hace la propia rejilla y se rehace sola
                         * al cambiar el tamaño de la ventana.
                         */
                        // Alto de fila con aire para que quepa la miniatura. El tema por defecto es
                        // de 42 px y con una foto dentro quedaba aplastada.
                        /*
                         * Pulsar la FILA abre la ficha, no solo el ojo.
                         *
                         * El botón se queda porque es lo que anuncia que la fila se puede abrir —sin
                         * él nadie adivina que hay algo debajo—, pero obligar a acertarle a un icono
                         * de 32 px cuando el resto de la fila no hace nada es pedirle puntería a
                         * quien solo quiere ver el carro.
                         */
                        onRowClicked: (e) => this.abrirFicha(e.data),
                        rowClass: 'bmos-fila-pulsable',
                        rowHeight: 68,
                        headerHeight: 44,
                        pagination: true,
                        paginationPageSize: 15,
                        paginationPageSizeSelector: [15, 30, 60],
                        /*
                         * El tema va por API de JavaScript: desde la v33 no hay CSS que importar, la
                         * rejilla inyecta el suyo. Se parte de Quartz —el que se parece a una tabla
                         * de producto y no a una hoja de cálculo— y se le pasan los colores del
                         * panel, para que el patio no desentone del resto del sistema.
                         *
                         * `themeCssLayer` mete su CSS en una capa propia para que no pelee con
                         * Tailwind por especificidad.
                         */
                        theme: ag.themeQuartz.withParams({
                            accentColor: '#4f46e5',
                            borderColor: '#eef1f6',
                            /*
                             * Cabecera BLANCA, no gris.
                             *
                             * La banda gris sobre una tarjeta blanca metía una segunda superficie
                             * en la misma caja y partía la tarjeta en dos. Con el mismo blanco del
                             * fondo, lo que separa la cabecera de las filas es una sola línea, y
                             * los rótulos en versalitas —el CSS los pone así— ya se distinguen de
                             * los datos sin necesidad de fondo.
                             */
                            headerBackgroundColor: '#ffffff',
                            headerTextColor: '#8894ab',
                            headerFontWeight: 600,
                            headerFontSize: 11,
                            // Heredada del panel: con la suya propia la rejilla cantaba al lado de
                            // las demás tablas.
                            fontFamily: 'inherit',
                            fontSize: 14,
                            foregroundColor: '#334155',
                            rowHoverColor: '#f6f8ff',
                            selectedRowBackgroundColor: '#eef2ff',
                            cellHorizontalPadding: 18,
                            /*
                             * SIN filas alternas.
                             *
                             * Las llevaba para no perder el renglón con doce columnas, pero el
                             * rayado y la línea entre filas hacen el mismo trabajo dos veces, y
                             * juntos ensucian. Se queda la línea —más limpia— y el trabajo de
                             * seguir la fila lo hace el resaltado al pasar el ratón, que además
                             * señala la fila que de verdad se está mirando.
                             */
                            oddRowBackgroundColor: '#ffffff',
                            headerRowBorder: { width: 1, color: '#e8ecf3' },
                            rowBorder: { width: 1, color: '#f4f6fa' },
                            // El marco lo pone la tarjeta: si la rejilla dibujara el suyo, se verían
                            // dos bordes pegados.
                            wrapperBorder: false,
                            wrapperBorderRadius: 0,
                        }),
                        themeCssLayer: 'ag-grid',
                        localeText: {
                            noRowsToShow: 'No hay vehículos que mostrar.',
                            // El pie de paginación, en español: por omisión sale en inglés y es lo
                            // primero que se lee al final de la tabla.
                            page: 'Página',
                            to: 'a',
                            of: 'de',
                            firstPage: 'Primera',
                            previousPage: 'Anterior',
                            nextPage: 'Siguiente',
                            lastPage: 'Última',
                            pageSizeSelectorLabel: 'Por página:',
                            filterOoo: 'Filtrar…',
                            equals: 'Igual a',
                            notEqual: 'Distinto de',
                            contains: 'Contiene',
                            notContains: 'No contiene',
                            startsWith: 'Empieza por',
                            endsWith: 'Termina en',
                            blank: 'Vacío',
                            notBlank: 'Con contenido',
                            lessThan: 'Menor que',
                            greaterThan: 'Mayor que',
                            inRange: 'Entre',
                            applyFilter: 'Aplicar',
                            resetFilter: 'Quitar',
                            andCondition: 'Y',
                            orCondition: 'O',
                        },
                    });

                    /*
                     * Al cambiar el tamaño de la ventana, se rehacen las columnas SOLO si cambia el
                     * tramo de ancho.
                     *
                     * Redimensionar dispara decenas de eventos por segundo; rehacer la tabla en cada
                     * uno la dejaría parpadeando. El `flex` ya reparte el ancho solo en todos los
                     * casos: lo único que hay que rehacer es qué columnas existen, y eso solo cambia
                     * al cruzar un tramo.
                     */
                    const tramo = (a) => [1150, 1210, 1320, 1445, 1565].filter((c) => a >= c).length;
                    let tramoActual = tramo(window.innerWidth);

                    window.addEventListener('resize', () => {
                        clearTimeout(this.temporizador);
                        this.temporizador = setTimeout(() => {
                            const nuevo = tramo(window.innerWidth);
                            if (nuevo === tramoActual) return;
                            tramoActual = nuevo;
                            this.api?.setGridOption('columnDefs', construir(window.innerWidth));
                        }, 200);
                    });

                    await this.recargar();
                    this.cargando = false;
                },

                async recargar() {
                    if (!this.api) return;

                    const url = new URL('{{ route('panel.vehicles.data') }}', window.location.origin);
                    if (this.texto) url.searchParams.set('q', this.texto);
                    if (this.marca) url.searchParams.set('marca', this.marca);
                    if (this.anio) url.searchParams.set('anio', this.anio);
                    if (this.estado) url.searchParams.set('estado', this.estado);

                    try {
                        const r = await fetch(url, { headers: { Accept: 'application/json' } });
                        const datos = await r.json();

                        this.filas = datos.filas || [];
                        this.api.setGridOption('rowData', this.filas);
                        const n = this.filas.length;
                        this.cuantas = n === 1 ? '1 vehículo' : n + ' vehículos';
                        if (datos.tope_alcanzado) this.cuantas += ' (se muestran los primeros)';
                    } catch (e) {
                        this.fallo = 'No se pudieron traer los vehículos.';
                    }
                },

                exportar() {
                    this.api?.exportDataAsCsv({ fileName: 'patio.csv' });
                },
            };
        }
    </script>
</x-layouts.admin>
