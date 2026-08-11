@php
    use App\Modules\Inventory\Enums\SelectionType;
@endphp
<x-layouts.admin title="Tamaños y sabores" heading="Tamaños y sabores"
                 subheading="Grupos de opciones que se preguntan al vender: tamaños, sabores, extras">
    <div x-data="optionGroupsCrud()">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <p class="max-w-2xl text-sm text-slate-500">
                Un grupo se crea una vez y se asigna a todos los productos que lo necesiten. Cambiar aquí el
                recargo de «2 bolas» lo cambia en todos los helados a la vez; las ventas ya cobradas
                <b>no</b> se tocan, porque cada ticket guarda lo que se vendió ese día.
            </p>
            <x-panel.create-modal title="Nuevo grupo" label="Nuevo grupo" form="group_create"
                                  :action="route('panel.option-groups.store')">
                <x-panel.field name="name" label="Nombre del grupo" required placeholder="Tamaño" />
                <div>
                    <label class="bmos-field-label">Cómo se elige</label>
                    <select name="selection_type" class="bmos-input" required>
                        @foreach (SelectionType::cases() as $tipo)
                            <option value="{{ $tipo->value }}" @selected(old('selection_type') === $tipo->value)>{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        «Elegir una» para tamaños. «Elegir varias» para sabores o extras.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-panel.field name="min_selections" label="Mínimo (solo si son varias)" type="number" min="0" :value="old('min_selections')" />
                    <x-panel.field name="max_selections" label="Máximo (opcional)" type="number" min="1" :value="old('max_selections')" />
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="is_required" value="1" @checked(old('is_required')) class="rounded border-slate-300 text-indigo-600">
                    Obligatorio: no se puede vender sin elegir
                </label>
            </x-panel.create-modal>
        </div>

        @if ($groups->isEmpty())
            <div class="bmos-card bmos-card-pad">
                <div class="bmos-empty">
                    <p class="font-medium text-slate-600">Todavía no hay grupos de opciones.</p>
                    <p class="mt-1 text-sm">
                        Crea «Tamaño» con las opciones 1 bola, 2 bolas y 3 bolas, y asígnalo a tus helados.
                        Al venderlos, el punto de venta preguntará el tamaño y sumará el recargo.
                    </p>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                @foreach ($groups as $group)
                    @php $asignados = $group->products->pluck('id')->all(); @endphp
                    <div class="bmos-card bmos-card-pad">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-lg font-semibold text-slate-800">{{ $group->name }}</p>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <span class="bmos-badge badge-blue">{{ $group->selection_type->label() }}</span>
                                    @if ($group->is_required)
                                        <span class="bmos-badge badge-amber">Obligatorio</span>
                                    @endif
                                    @if ($group->isMultiple() && ($group->min_selections > 0 || $group->max_selections))
                                        <span class="bmos-badge badge-gray">
                                            {{ $group->min_selections > 0 ? 'mín. '.$group->min_selections : '' }}
                                            {{ $group->max_selections ? 'máx. '.$group->max_selections : '' }}
                                        </span>
                                    @endif
                                    <span class="bmos-badge {{ $group->is_active ? 'badge-green' : 'badge-gray' }}">
                                        {{ $group->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </div>
                            </div>
                            <button type="button" class="bmos-btn bmos-btn-ghost text-xs"
                                    @click="editarGrupo(@js([
                                        'id' => $group->id,
                                        'name' => $group->name,
                                        'selection_type' => $group->selection_type->value,
                                        'is_required' => $group->is_required,
                                        'min_selections' => $group->min_selections,
                                        'max_selections' => $group->max_selections,
                                        'is_active' => $group->is_active,
                                    ]))">Editar</button>
                        </div>

                        {{-- Opciones del grupo --}}
                        <div class="mt-4 border-t border-slate-100 pt-3">
                            <p class="bmos-field-label">Opciones</p>
                            @if ($group->options->isEmpty())
                                <p class="mt-1 text-sm text-slate-400">
                                    Sin opciones todavía. Un grupo vacío no se le pregunta a nadie al vender.
                                </p>
                            @else
                                <ul class="mt-1 divide-y divide-slate-100">
                                    @foreach ($group->options as $option)
                                        <li class="flex items-center justify-between gap-2 py-1.5">
                                            <span class="text-sm {{ $option->is_active ? 'text-slate-700' : 'text-slate-400 line-through' }}">
                                                {{ $option->name }}
                                            </span>
                                            <span class="flex items-center gap-2">
                                                <span class="text-sm tabular-nums {{ (float) $option->price_delta > 0 ? 'text-emerald-600' : ((float) $option->price_delta < 0 ? 'text-rose-600' : 'text-slate-400') }}">
                                                    {{ (float) $option->price_delta > 0 ? '+' : '' }}{{ number_format((float) $option->price_delta, 2) }}
                                                </span>
                                                <button type="button" class="bmos-btn bmos-btn-ghost text-xs"
                                                        @click="editarOpcion(@js([
                                                            'id' => $option->id,
                                                            'name' => $option->name,
                                                            'price_delta' => (string) $option->price_delta,
                                                            'is_active' => $option->is_active,
                                                        ]))">Editar</button>
                                                <form method="POST" action="{{ route('panel.options.destroy', $option) }}" class="inline"
                                                      onsubmit="return confirm('¿Eliminar «{{ $option->name }}»? Los tickets antiguos la seguirán mostrando tal como se vendió.')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="bmos-btn bmos-btn-ghost text-xs text-rose-600">✕</button>
                                                </form>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            {{-- Alta rápida en línea: crear una opción es lo que más se repite, así que no
                                 se esconde tras un modal. --}}
                            <form method="POST" action="{{ route('panel.options.store', $group) }}"
                                  class="mt-2 flex items-end gap-2">
                                @csrf
                                <div class="flex-1">
                                    <input name="name" class="bmos-input" placeholder="2 bolas" required>
                                </div>
                                <div class="w-28">
                                    <input name="price_delta" type="number" step="0.01" class="bmos-input" placeholder="+60" value="0" required>
                                </div>
                                <button type="submit" class="bmos-btn bmos-btn-primary">Añadir</button>
                            </form>
                        </div>

                        {{-- Productos que lo usan --}}
                        <div class="mt-4 border-t border-slate-100 pt-3">
                            <div class="flex items-center justify-between gap-2">
                                <p class="bmos-field-label">Se pregunta en {{ count($asignados) }} producto{{ count($asignados) === 1 ? '' : 's' }}</p>
                                <button type="button" class="bmos-btn bmos-btn-ghost text-xs"
                                        @click="asignar({{ $group->id }}, @js($asignados))">Elegir productos</button>
                            </div>
                            @if ($group->products->isNotEmpty())
                                <p class="mt-1 text-xs text-slate-500">{{ $group->products->pluck('name')->join(', ') }}</p>
                            @endif
                        </div>

                        <div class="mt-4 flex justify-end border-t border-slate-100 pt-3">
                            <form method="POST" action="{{ route('panel.option-groups.destroy', $group) }}"
                                  onsubmit="return confirm('¿Eliminar el grupo «{{ $group->name }}»? Dejará de preguntarse en los productos que lo usaban.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="bmos-btn bmos-btn-ghost text-xs text-rose-600">Eliminar grupo</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Modal: editar grupo --}}
        <div x-show="grupoAbierto" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
             @keydown.escape.window="grupoAbierto=false">
            <div @click.outside="grupoAbierto=false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar grupo</h3>
                    <button type="button" @click="grupoAbierto=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'group_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="urlGrupo" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="group_edit">
                    <div>
                        <label class="bmos-field-label">Nombre</label>
                        <input name="name" x-model="grupo.name" class="bmos-input" required>
                    </div>
                    <div>
                        <label class="bmos-field-label">Cómo se elige</label>
                        <select name="selection_type" x-model="grupo.selection_type" class="bmos-input" required>
                            @foreach (SelectionType::cases() as $tipo)
                                <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Los límites solo se muestran cuando aplican: en «elegir una» no significan nada
                         y el servidor los descarta igualmente. --}}
                    <div class="grid grid-cols-2 gap-3" x-show="grupo.selection_type === 'multiple'" x-cloak>
                        <div>
                            <label class="bmos-field-label">Mínimo</label>
                            <input name="min_selections" type="number" min="0" x-model="grupo.min_selections" class="bmos-input">
                        </div>
                        <div>
                            <label class="bmos-field-label">Máximo (opcional)</label>
                            <input name="max_selections" type="number" min="1" x-model="grupo.max_selections" class="bmos-input">
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="is_required" value="0">
                        <input type="checkbox" name="is_required" value="1" x-model="grupo.is_required" class="rounded border-slate-300 text-indigo-600">
                        Obligatorio
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="grupo.is_active" class="rounded border-slate-300 text-indigo-600">
                        Activo
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="grupoAbierto=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal: editar opción --}}
        <div x-show="opcionAbierta" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
             @keydown.escape.window="opcionAbierta=false">
            <div @click.outside="opcionAbierta=false" x-transition class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar opción</h3>
                    <button type="button" @click="opcionAbierta=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <form method="POST" :action="urlOpcion" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <div>
                        <label class="bmos-field-label">Nombre</label>
                        <input name="name" x-model="opcion.name" class="bmos-input" required>
                    </div>
                    <div>
                        <label class="bmos-field-label">Recargo</label>
                        <input name="price_delta" type="number" step="0.01" x-model="opcion.price_delta" class="bmos-input" required>
                        <p class="mt-1 text-xs text-slate-400">
                            Lo que suma al precio del producto. Puede ser 0, o negativo para descontar.
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="opcion.is_active" class="rounded border-slate-300 text-indigo-600">
                        Disponible
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="opcionAbierta=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Modal: elegir productos --}}
        <div x-show="productosAbierto" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
             @keydown.escape.window="productosAbierto=false">
            <div @click.outside="productosAbierto=false" x-transition class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">¿En qué productos se pregunta?</h3>
                    <button type="button" @click="productosAbierto=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                <form method="POST" :action="urlProductos" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    {{-- Sin esto, desmarcar todos no enviaría el campo y el servidor no sabría
                         distinguir «ninguno» de «no se tocó». --}}
                    <input type="hidden" name="products[]" value="">
                    <input x-model="filtro" class="bmos-input" placeholder="Buscar producto…">
                    <div class="max-h-80 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                        @forelse ($products as $product)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50"
                                   x-show="coincide(@js($product->name))">
                                <input type="checkbox" name="products[]" value="{{ $product->id }}"
                                       x-model.number="seleccionados" class="rounded border-slate-300 text-indigo-600">
                                {{ $product->name }}
                            </label>
                        @empty
                            <p class="bmos-empty">No hay productos todavía.</p>
                        @endforelse
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="productosAbierto=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function optionGroupsCrud() {
            return {
                grupoAbierto: {{ old('_form') === 'group_edit' ? 'true' : 'false' }},
                opcionAbierta: false,
                productosAbierto: false,
                filtro: '',
                grupoId: null,
                seleccionados: [],
                grupo: @js([
                    'id' => old('id'), 'name' => old('name'), 'selection_type' => old('selection_type', 'single'),
                    'is_required' => false, 'min_selections' => 0, 'max_selections' => '', 'is_active' => true,
                ]),
                opcion: { id: null, name: '', price_delta: '0', is_active: true },

                get urlGrupo() { return '{{ url('panel/opciones') }}/' + this.grupo.id; },
                get urlOpcion() { return '{{ url('panel/opcion') }}/' + this.opcion.id; },
                get urlProductos() { return '{{ url('panel/opciones') }}/' + this.grupoId + '/productos'; },

                editarGrupo(row) {
                    this.grupo = { ...row, max_selections: row.max_selections ?? '' };
                    this.grupoAbierto = true;
                },

                editarOpcion(row) {
                    this.opcion = { ...row };
                    this.opcionAbierta = true;
                },

                asignar(grupoId, ids) {
                    this.grupoId = grupoId;
                    this.seleccionados = ids;
                    this.filtro = '';
                    this.productosAbierto = true;
                },

                coincide(nombre) {
                    return this.filtro === '' || nombre.toLowerCase().includes(this.filtro.toLowerCase());
                },
            };
        }
    </script>
</x-layouts.admin>
