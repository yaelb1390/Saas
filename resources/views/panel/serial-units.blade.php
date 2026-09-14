{{--
    Unidades en serie: la rejilla de TODAS las unidades de la empresa.

    Es la pantalla para operar sobre lo que ya se escaneó: borrar la que coló mal, corregir un usado
    mal tasado, o saltar al historial de una serie. Se filtra por producto y por estado.

    La tabla lleva `bmos-tabla-tarjetas`: en el teléfono cada fila se lee como una tarjeta, con el
    rótulo de cada dato al lado, sin arrastrar el dedo.

    LAS TRES REGLAS que se ven aquí:
      - Borrar solo lo DISPONIBLE. Al borrar baja el stock en uno, por la puerta con kardex.
      - Una VENDIDA no se toca: es historial y garantía. Solo su enlace al historial.
      - Editar corrige precio, condición y color. Nunca la serie: es su identidad.
--}}
@php
    $filtros = ['disponibles' => 'Disponibles', 'vendidas' => 'Vendidas', 'todas' => 'Todas'];
@endphp

<x-layouts.admin title="Unidades en serie" heading="Unidades en serie"
                 subheading="Cada unidad por su número de serie: bórrala, corrígela o mira su historial">
    <div x-data="unidadesEnSerie()">
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="font-semibold text-slate-800">Unidades</p>
                    <div class="inline-flex rounded-lg bg-slate-100 p-1">
                        @foreach ($filtros as $clave => $etiqueta)
                            <a href="{{ request()->fullUrlWithQuery(['estado' => $clave, 'page' => null]) }}"
                               class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $estado === $clave ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                                {{ $etiqueta }}
                            </a>
                        @endforeach
                    </div>
                </div>

                {{-- Filtro por producto. Recarga al elegir, arrastrando el estado para no perderlo. --}}
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="estado" value="{{ $estado }}">
                    <label class="sr-only" for="filtro-producto">Producto</label>
                    <select id="filtro-producto" name="product_id" onchange="this.form.submit()" class="bmos-input">
                        <option value="">Todos los productos</option>
                        @foreach ($serializados as $p)
                            <option value="{{ $p->id }}" @selected($productId === $p->id)>{{ $p->name }}@if ($p->sku) · {{ $p->sku }}@endif</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table bmos-tabla-tarjetas">
                    <thead>
                        <tr>
                            <th>Serie</th>
                            <th>Producto</th>
                            <th>Estado</th>
                            <th>Condición</th>
                            <th>Color</th>
                            <th class="text-right">Precio</th>
                            <th>Almacén</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($unidades as $u)
                            <tr>
                                <td data-rotulo="Serie" class="font-mono text-slate-700">{{ $u->serial }}</td>
                                <td data-rotulo="Producto" class="font-medium text-slate-800">{{ $u->product?->name ?? '—' }}</td>
                                <td data-rotulo="Estado">
                                    @if ($u->status === \App\Modules\Inventory\Models\ProductUnit::DISPONIBLE)
                                        <span class="bmos-badge badge-green">Disponible</span>
                                    @elseif ($u->status === \App\Modules\Inventory\Models\ProductUnit::VENDIDA)
                                        <span class="bmos-badge badge-gray">Vendida</span>
                                    @elseif ($u->status === \App\Modules\Inventory\Models\ProductUnit::RESERVADA)
                                        <span class="bmos-badge badge-amber">Reservada</span>
                                    @else
                                        <span class="bmos-badge badge-blue">Devuelta</span>
                                    @endif
                                </td>
                                <td data-rotulo="Condición" class="text-slate-500">{{ $u->condition ?? '—' }}</td>
                                <td data-rotulo="Color" class="text-slate-500">{{ $u->color ?? '—' }}</td>
                                <td data-rotulo="Precio" class="text-right tabular-nums text-slate-700">{{ number_format((float) $u->precioDeVenta(), 2) }}</td>
                                <td data-rotulo="Almacén" class="text-slate-500">{{ $u->warehouse?->name ?? '—' }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        {{-- El historial: siempre, también para las vendidas —es justo la que más se busca. --}}
                                        <a href="{{ route('panel.serial.history', ['serie' => $u->serial]) }}"
                                           title="Ver historial"
                                           class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600">
                                            <x-icono name="id" class="h-4 w-4" style="width:1.15rem;height:1.15rem" />
                                        </a>

                                        @can('stock.adjust')
                                            @if ($u->estaDisponible())
                                                {{-- Editar: precio, condición y color. Solo lo disponible; corregir una
                                                     vendida no cambia una venta cerrada. --}}
                                                <button type="button" title="Editar"
                                                        @click="editar({ id: {{ $u->id }}, serial: @js($u->serial), condition: @js($u->condition), color: @js($u->color), price: '{{ $u->price }}' })"
                                                        class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-indigo-600">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                                </button>

                                                {{-- Borrar: baja el stock en uno, por la puerta con kardex. --}}
                                                <x-panel.confirm-action
                                                    :action="route('panel.products.units.destroy', $u)"
                                                    title="¿Dar de baja «{{ $u->serial }}»?"
                                                    message="Se quita esta unidad del inventario."
                                                    note="El stock del producto baja en uno. La unidad queda archivada; su historial se conserva."
                                                    confirm="Dar de baja"
                                                    tooltip="Borrar"
                                                    class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                                </x-panel.confirm-action>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="bmos-empty">
                                @if ($estado === 'vendidas')
                                    Todavía no se ha vendido ninguna unidad con serie.
                                @elseif ($productId)
                                    Este producto no tiene unidades en este estado.
                                @else
                                    Aún no hay unidades en serie. Dales de alta en
                                    <a href="{{ route('panel.serial.scan') }}" class="font-medium text-indigo-600">Escaneo de series</a>.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $unidades->links() }}</div>

        {{-- Modal de edición, uno solo, reutilizado por todas las filas.

             Solo precio, condición y color. NO la serie —se muestra de solo lectura, para saber cuál
             se está tocando— ni el estado. Se reabre solo si su propio envío tuvo errores. --}}
        <div x-show="abierto" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
             @keydown.escape.window="abierto = false">
            <div @click.outside="abierto = false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar unidad</h3>
                    <button type="button" @click="abierto = false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>

                @if (old('_form') === 'unit_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                <form method="POST" :action="accionUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="unit_edit">
                    {{-- El id no lo usa el servidor —la unidad viene por la URL— pero deja repoblar el
                         modal si la validación devuelve al usuario aquí. --}}
                    <input type="hidden" name="id" x-model="fila.id">

                    <div>
                        <label class="bmos-field-label">Serie</label>
                        <input type="text" class="bmos-input font-mono bg-slate-50 text-slate-500" :value="fila.serial" disabled>
                        <p class="mt-1 text-xs text-slate-400">La serie no se cambia: es la identidad de la unidad.</p>
                    </div>
                    <div>
                        <label class="bmos-field-label" for="edit-condicion">Condición</label>
                        <select id="edit-condicion" name="condition" x-model="fila.condition" class="bmos-input">
                            <option value="">—</option>
                            <option value="nuevo">Nuevo</option>
                            <option value="usado">Usado</option>
                            <option value="reacondicionado">Reacondicionado</option>
                        </select>
                    </div>
                    <div>
                        <label class="bmos-field-label" for="edit-color">Color</label>
                        <input id="edit-color" type="text" name="color" x-model="fila.color" maxlength="60" class="bmos-input" placeholder="ej. Negro">
                    </div>
                    <div>
                        <label class="bmos-field-label" for="edit-precio">Precio de venta</label>
                        <input id="edit-precio" type="number" step="0.01" min="0" name="price" x-model="fila.price" class="bmos-input" placeholder="del producto">
                        <p class="mt-1 text-xs text-slate-400">Vacío = el precio del catálogo.</p>
                    </div>

                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="abierto = false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function unidadesEnSerie() {
            return {
                abierto: false,
                fila: { id: '', serial: '', condition: '', color: '', price: '' },

                get accionUrl() {
                    return '{{ url('panel/inventario/unidades') }}/' + this.fila.id;
                },

                editar(datos) {
                    // Un null del servidor entra como cadena vacía para que el <select> y el <input>
                    // no muestren «null».
                    this.fila = {
                        id: datos.id,
                        serial: datos.serial ?? '',
                        condition: datos.condition ?? '',
                        color: datos.color ?? '',
                        price: datos.price ?? '',
                    };
                    this.abierto = true;
                },

                init() {
                    // Si la edición falló la validación, se reabre con lo que se había escrito.
                    @if (old('_form') === 'unit_edit')
                        this.fila = {
                            id: '{{ old('id') }}',
                            serial: @js(old('serial')),
                            condition: @js(old('condition')),
                            color: @js(old('color')),
                            price: @js(old('price')),
                        };
                        this.abierto = true;
                    @endif
                },
            };
        }
    </script>

    @include('partials.toast')
</x-layouts.admin>
