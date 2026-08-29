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
                <div class="bmos-stat">
                    <div class="bmos-stat-icon tone-indigo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>
                    </div>
                    <p class="bmos-stat-label">En el patio</p>
                    <p class="bmos-stat-value">{{ $resumen['total'] }}</p>
                </div>

                <div class="bmos-stat">
                    <div class="bmos-stat-icon tone-emerald">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Disponibles</p>
                    <p class="bmos-stat-value text-emerald-600">{{ $resumen['disponibles'] }}</p>
                </div>

                <div class="bmos-stat">
                    <div class="bmos-stat-icon tone-amber">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Apartados</p>
                    <p class="bmos-stat-value text-amber-600">{{ $resumen['apartados'] }}</p>
                </div>

                <div class="bmos-stat">
                    <div class="bmos-stat-icon tone-sky">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797-2.101c.727-.198 1.453.164 1.453.925V19.5a2.25 2.25 0 0 1-2.25 2.25H2.25V18.75Zm0 0a2.25 2.25 0 0 0 2.25 2.25h.75m-3-2.25V6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-.75m-9-6a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0Z"/></svg>
                    </div>
                    <p class="bmos-stat-label">Vendidos</p>
                    <p class="bmos-stat-value text-sky-600">{{ $resumen['vendidos'] }}</p>
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
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Cómo está el patio</p>
                            <div class="h-44"><canvas x-ref="estados"></canvas></div>
                        </div>
                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Unidades por marca</p>
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
                            {{-- Con contorno y no con `bmos-btn-ghost`: aquel es texto a secas —vale
                                 para una acción secundaria dentro de una fila— y aquí, suelto al
                                 final de una hilera de desplegables, no parecía pulsable. --}}
                            <button type="button" @click="limpiar()"
                                    class="bmos-btn w-full border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 disabled:opacity-40"
                                    x-bind:disabled="!hayFiltros()">
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                {{-- ---------------------------------------------------------------- La rejilla --}}
                <div class="bmos-card">
                    <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
                        <p class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5 text-indigo-500">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>
                            </svg>
                            Inventario de vehículos
                        </p>

                        <span class="text-sm text-slate-400" x-text="cuantas"></span>

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
                                        <label class="bmos-field-label">Foto</label>
                                        <input type="file" name="photo" accept="image/*" class="bmos-input">
                                        <p class="mt-1 text-xs text-slate-400">
                                            Una por unidad. Se recorta sola a formato horizontal.
                                        </p>
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
                    <div x-ref="rejilla" x-show="!cargando && !fallo && vista === 'tabla'" class="bmos-rejilla"></div>

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
                <div x-show="ficha" x-cloak
                     class="fixed inset-0 z-50 flex justify-end bg-slate-900/40"
                     @keydown.escape.window="ficha = null">
                    <div @click.outside="ficha = null" x-transition
                         class="h-full w-full max-w-md overflow-y-auto bg-white shadow-2xl">
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

                                <div class="p-5">
                                    <template x-if="ficha.foto">
                                        <img :src="ficha.foto" alt="" class="mb-4 w-full rounded-xl border border-slate-100 object-cover">
                                    </template>

                                    <div class="mb-4 flex flex-wrap items-center gap-2">
                                        <span class="bmos-badge" :class="tonoDe(ficha.estado)" x-text="ficha.estado_texto"></span>
                                        <span class="text-lg font-semibold text-slate-800" x-text="pesos(ficha.precio)"></span>
                                    </div>

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

                                    <div class="mt-5">
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Taller</p>
                                        <p x-show="detalle && detalle.trabajos.length === 0" class="text-sm text-slate-400">
                                            No se le ha hecho nada todavía.
                                        </p>
                                        <template x-for="(t, i) in (detalle ? detalle.trabajos : [])" :key="i">
                                            <div class="flex items-start justify-between gap-3 border-b border-slate-100 py-2 text-sm last:border-0">
                                                <div>
                                                    <p class="text-slate-700" x-text="t.descripcion"></p>
                                                    <p class="text-xs text-slate-400">
                                                        <span x-text="t.quien || 'sin anotar quién'"></span>
                                                        <span x-show="t.fecha"> · <span x-text="t.fecha"></span></span>
                                                        · <span x-text="t.estado"></span>
                                                    </p>
                                                </div>
                                                <span x-show="t.costo !== null" class="shrink-0 text-slate-600" x-text="pesos(t.costo)"></span>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="mt-6 flex flex-wrap gap-2">
                                        @can('vehicle_deals.manage')
                                            <a href="{{ route('panel.vehicle-deals') }}" class="bmos-btn bmos-btn-primary">Apartar o vender</a>
                                        @endcan
                                        @can('vehicle_jobs.manage')
                                            <a :href="'{{ route('panel.vehicle-jobs') }}?vehiculo=' + ficha.id"
                                               class="bmos-btn bmos-btn-ghost">Ver su taller</a>
                                        @endcan
                                    </div>
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
                            // El agujero grande deja sitio y hace que se lea como un resumen y no
                            // como una tarta de porcentajes.
                            cutout: '62%',
                            plugins: {
                                legend: { position: 'right', labels: { boxWidth: 10, font: { size: 12 } } },
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
                                borderRadius: 5,
                                barThickness: 16,
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
                detalle: null,   // sus trabajos y su trato, pedidos aparte

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

                async abrirFicha(fila) {
                    this.ficha = fila;
                    this.detalle = null;

                    try {
                        const r = await fetch('/panel/vehiculos/' + fila.id + '/ficha', { headers: { Accept: 'application/json' } });
                        this.detalle = await r.json();
                    } catch (e) {
                        // La ficha ya enseña lo esencial con lo que la rejilla tiene en memoria; si
                        // el detalle no llega, se queda sin taller pero no en blanco.
                        this.detalle = { trabajos: [], trato: null };
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
                            headerName: 'Foto', field: 'foto', width: 92, sortable: false, filter: false,
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
                            valueFormatter: dinero, cellClass: 'bmos-celda-dinero',
                        },
                        {
                            field: 'estado_texto', headerName: 'Estado', flex: 0.9, minWidth: 105, sortable: true,
                            // Centrada: el precio de al lado va pegado a la derecha, y sin esto la
                            // píldora quedaba tocándolo y las dos columnas se leían como una sola.
                            cellClass: 'bmos-celda-estado',
                            cellRenderer: (p) => {
                                const s = document.createElement('span');
                                s.className = 'bmos-badge ' + this.tonoDe(p.data.estado);
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
                     */
                    if (ancho >= 1270) {
                        // El chasis es el primero en caerse: ocupa mucho, se busca desde arriba y
                        // para reconocer la unidad ya están la foto, la marca y el modelo.
                        columnas.splice(1, 0, {
                            field: 'vin', headerName: 'Chasis', flex: 1.3, minWidth: 100,
                            cellClass: 'bmos-celda-mono',
                        });
                    }

                    if (ancho >= 1100) {
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

                                const barra = document.createElement('span');
                                barra.className = 'bmos-antiguedad-barra';
                                // Se topa al 100 %: a partir de medio año la barra ya está llena y
                                // lo que importa es el número.
                                barra.style.width = Math.min(100, Math.round((p.value / 180) * 100)) + '%';

                                const txt = document.createElement('span');
                                txt.className = 'bmos-antiguedad-dias';
                                txt.textContent = p.value + ' d';

                                caja.appendChild(txt);
                                caja.appendChild(barra);

                                return caja;
                            },
                        },);
                    }

                    if (ancho >= 1160) {
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
                    if (puedeGestionar && ancho >= 1510) {
                        columnas.push(
                            { field: 'costo_real', headerName: 'Costo real', flex: 1.1, minWidth: 120, valueFormatter: dinero, cellClass: 'bmos-celda-dinero' },
                        );
                    }

                    // El margen aguanta más: es LA cifra de la pantalla, así que se conserva hasta
                    // que de verdad no cabe.
                    if (puedeGestionar && ancho >= 1390) {
                        columnas.push({
                            field: 'margen', headerName: 'Margen', flex: 1.1, minWidth: 115,
                            valueFormatter: dinero, cellClass: 'bmos-celda-dinero',
                            // Un margen negativo es dinero perdido: tiene que saltar a la vista.
                            cellClassRules: { 'bmos-celda-perdida': (p) => Number(p.value) < 0 },
                        });
                    }

                    columnas.push({
                        headerName: '', field: 'id', width: 58, minWidth: 58, sortable: false, filter: false, pinned: 'right',
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
                        rowHeight: 64,
                        headerHeight: 46,
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
                            headerBackgroundColor: '#f8fafc',
                            headerTextColor: '#475569',
                            headerFontWeight: 600,
                            headerFontSize: 12,
                            // Heredada del panel: con la suya propia la rejilla cantaba al lado de
                            // las demás tablas.
                            fontFamily: 'inherit',
                            fontSize: 14,
                            foregroundColor: '#334155',
                            rowHoverColor: '#f8faff',
                            selectedRowBackgroundColor: '#eef2ff',
                            cellHorizontalPadding: 14,
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
                    const tramo = (a) => [1100, 1160, 1270, 1390, 1510].filter((c) => a >= c).length;
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
