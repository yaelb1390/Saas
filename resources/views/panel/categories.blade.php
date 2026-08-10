<x-layouts.admin title="Categorías" heading="Categorías"
                 subheading="Agrupan los productos y ordenan la rejilla del punto de venta">
    <div x-data="categoriesCrud()">
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Categorías</p>
                @can('categories.manage')
                    <x-panel.create-modal title="Nueva categoría" label="Nueva categoría" form="category_create"
                                          :action="route('panel.categories.store')">
                        <x-panel.field name="name" label="Nombre" required placeholder="Helados" />
                        <x-panel.category-icon-picker :selected="old('icon')" />
                        <div>
                            <label class="bmos-field-label">Categoría padre (opcional)</label>
                            <select name="parent_id" class="bmos-input">
                                <option value="">— Ninguna —</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected(old('parent_id') == $cat->id)>{{ $cat->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">Úsala para anidar, por ejemplo «Helados» → «Paletas».</p>
                        </div>
                    </x-panel.create-modal>
                @endcan
            </div>

            @if ($categories->isEmpty())
                <div class="bmos-empty">
                    <p class="font-medium text-slate-600">Todavía no hay categorías.</p>
                    <p class="mt-1 text-sm">Crea la primera para poder agrupar productos y filtrarlos en el punto de venta.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="bmos-table">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Categoría padre</th>
                                <th class="text-right">Productos</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr>
                                    <td class="font-medium text-slate-700">
                                        <span class="mr-1.5">{{ \App\Modules\Inventory\Support\CategoryIcons::resolve($category->icon) }}</span>
                                        {{ $category->name }}
                                    </td>
                                    <td class="text-slate-500">{{ $category->parent?->name ?? '—' }}</td>
                                    <td class="text-right tabular-nums text-slate-600">{{ $category->products_count }}</td>
                                    <td>
                                        <span class="bmos-badge {{ $category->is_active ? 'badge-green' : 'badge-gray' }}">
                                            {{ $category->is_active ? 'Activa' : 'Inactiva' }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        @can('categories.manage')
                                            <button type="button" class="bmos-btn bmos-btn-ghost text-xs"
                                                    @click="edit(@js([
                                                        'id' => $category->id,
                                                        'name' => $category->name,
                                                        'parent_id' => $category->parent_id,
                                                        'icon' => $category->icon,
                                                        'is_active' => $category->is_active,
                                                    ]))">
                                                Editar
                                            </button>
                                            <form method="POST" action="{{ route('panel.categories.destroy', $category) }}"
                                                  class="inline"
                                                  onsubmit="return confirm('¿Eliminar «{{ $category->name }}»? Sus productos quedarán sin categoría.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="bmos-btn bmos-btn-ghost text-xs text-rose-600">Eliminar</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Modal de edición: mismo patrón que el de productos (se reabre solo si la validación falló). --}}
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 py-10"
             @keydown.escape.window="open=false">
            <div @click.outside="open=false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-800">Editar categoría</h3>
                    <button type="button" @click="open=false" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>
                @if (old('_form') === 'category_edit' && $errors->any())
                    <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                        <ul class="list-disc pl-4">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" :action="editUrl" class="space-y-3">
                    @csrf
                    <input type="hidden" name="_method" value="PUT">
                    <input type="hidden" name="_form" value="category_edit">
                    <div>
                        <label class="bmos-field-label">Nombre</label>
                        <input name="name" x-model="row.name" class="bmos-input" required>
                    </div>

                    {{-- Mismo selector que en el alta, pero atado a la fila que se está editando. --}}
                    <div>
                        <input type="hidden" name="icon" :value="row.icon">
                        <div class="flex items-center justify-between gap-2">
                            <label class="bmos-field-label">Icono (opcional)</label>
                            <button type="button" x-show="row.icon" x-cloak @click="row.icon = null"
                                    class="text-xs font-semibold text-slate-400 hover:text-slate-600">Quitar</button>
                        </div>
                        @foreach (\App\Modules\Inventory\Support\CategoryIcons::GROUPS as $grupo => $iconos)
                            <p class="mt-2 mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $grupo }}</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($iconos as $icono)
                                    <button type="button" @click="row.icon = @js($icono)"
                                            :class="row.icon === @js($icono) ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'"
                                            class="grid h-11 w-11 place-items-center rounded-lg border text-xl transition hover:border-indigo-300">
                                        {{ $icono }}
                                    </button>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <label class="bmos-field-label">Categoría padre (opcional)</label>
                        <select name="parent_id" x-model="row.parent_id" class="bmos-input">
                            <option value="">— Ninguna —</option>
                            @foreach ($categories as $cat)
                                {{-- Una categoría no puede ser su propio padre: se oculta de su propia lista. --}}
                                <option value="{{ $cat->id }}" x-show="row.id !== {{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" x-model="row.is_active"
                               class="rounded border-slate-300 text-indigo-600">
                        Activa
                    </label>
                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="open=false" class="bmos-btn bmos-btn-ghost">Cancelar</button>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function categoriesCrud() {
            return {
                // Se reabre solo tras un fallo de validación, igual que el modal de productos.
                open: {{ old('_form') === 'category_edit' ? 'true' : 'false' }},
                row: @js([
                    'id' => old('id'), 'name' => old('name'), 'parent_id' => old('parent_id'),
                    'icon' => old('icon'), 'is_active' => true,
                ]),

                get editUrl() {
                    return '{{ url('panel/categorias') }}/' + this.row.id;
                },

                edit(row) {
                    this.row = { ...row, parent_id: row.parent_id ?? '' };
                    this.open = true;
                },
            };
        }
    </script>
</x-layouts.admin>
