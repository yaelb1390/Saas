{{--
    Los clientes del negocio.

    La columna de oportunidades y el subtítulo prometían un embudo de ventas que no existe: el
    servicio está escrito pero no tiene ni una ruta, así que nadie puede crear una oportunidad. Se
    deja de prometer hasta que se construya, detrás de su interruptor.

    Archivar y eliminar dejan de ser el mismo botón. El diálogo de antes decía «se archiva y deja de
    aparecer al vender», que es exactamente lo que hace `is_active` — y sin embargo hacía un borrado
    lógico que dejaba a null todas las relaciones de Ventas, Facturación, Entregas y Préstamos.
--}}
@php
    $estado = request('estado', 'activos');
    $filtros = ['activos' => 'Activos', 'archivados' => 'Archivados', 'todos' => 'Todos'];
@endphp

<x-layouts.admin title="CRM" heading="CRM" subheading="Los clientes del negocio y su historial">
    <div class="bmos-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <p class="font-semibold text-slate-800">Clientes</p>
                {{-- Archivar solo significa algo si se puede ver lo archivado y traerlo de vuelta. --}}
                <div class="inline-flex rounded-lg bg-slate-100 p-1">
                    @foreach ($filtros as $clave => $etiqueta)
                        <a href="{{ request()->fullUrlWithQuery(['estado' => $clave, 'page' => null]) }}"
                           class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $estado === $clave ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                            {{ $etiqueta }}
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                {{-- El filtro viaja dentro del buscador: si no, buscar lo borraría. --}}
                <x-panel.search-bar placeholder="Buscar cliente...">
                    <input type="hidden" name="estado" value="{{ $estado }}">
                </x-panel.search-bar>
                <x-panel.export-button route="panel.export.customers" />
                @can('customers.manage')
                    <x-panel.create-modal title="Nuevo cliente" label="Nuevo cliente" form="customer_create" :action="route('panel.customers.store')">
                        <x-panel.field name="name" label="Nombre" required placeholder="Nombre del cliente" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="cedula" label="Cédula" placeholder="001-0000000-0" />
                            <x-panel.field name="phone" label="Teléfono" placeholder="18095550000" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-panel.field name="tax_id" label="RNC (opcional)" />
                            <x-panel.field name="email" label="Correo" type="email" placeholder="cliente@correo.com" />
                        </div>
                        <x-panel.field name="address" label="Dirección" />
                        {{-- `x-panel.field` solo pinta <input>, así que el textarea va a mano, como
                             los <select> del resto del panel. --}}
                        <div>
                            <label class="bmos-field-label">Notas</label>
                            <textarea name="notes" rows="3" class="bmos-input" maxlength="2000"
                                      placeholder="Lo que hay que recordar de este cliente: paga los viernes, pide fiado…">{{ old('notes') }}</textarea>
                        </div>
                    </x-panel.create-modal>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="bmos-table">
                <thead><tr><th>Cliente</th><th>Teléfono</th><th>Correo</th><th class="text-right">Acciones</th></tr></thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr class="{{ $customer->is_active ? '' : 'opacity-60' }}">
                            <td class="font-medium">
                                <a href="{{ route('panel.customers.show', $customer) }}" class="text-indigo-600 hover:underline">{{ $customer->name }}</a>
                                @unless ($customer->is_active)
                                    <span class="bmos-badge badge-gray ml-1">Archivado</span>
                                @endunless
                                @if ($customer->cedula)<span class="block text-xs font-normal text-slate-400">Cédula: {{ $customer->cedula }}</span>@endif
                            </td>
                            <td>{{ $customer->phone ?? '—' }}</td>
                            <td class="text-slate-500">{{ $customer->email ?? '—' }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    @can('customers.manage')
                                        {{-- Enviar al cliente el enlace de su portal. Solo si la empresa
                                             tiene WhatsApp (el canal de entrega) y el cliente, teléfono.
                                             La ruta lo verifica igual: esto solo evita ofrecer un botón
                                             que fallaría. --}}
                                        @if ($portalEnabled && $customer->phone)
                                            <x-panel.confirm-action
                                                :action="route('panel.customers.portal', $customer)"
                                                method="POST"
                                                tone="neutral"
                                                title="¿Enviar el enlace del portal?"
                                                message="Se le manda a «{{ $customer->name }}» por WhatsApp al {{ $customer->phone }}."
                                                note="Con ese enlace podrá ver sus compras y su saldo sin necesidad de contraseña."
                                                confirm="Enviar por WhatsApp"
                                                tooltip="Enviar enlace del portal por WhatsApp"
                                                class="rounded-lg p-1.5 text-slate-500 hover:bg-emerald-50 hover:text-emerald-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                                            </x-panel.confirm-action>
                                        @endif

                                        {{-- El modal compartido, no uno a mano que arma su URL en
                                             JavaScript. El `form` lleva el id: con el mismo valor en
                                             quince filas, un error de validación abriría los quince. --}}
                                        <x-panel.edit-modal
                                            title="Editar cliente"
                                            :form="'customer_edit_'.$customer->id"
                                            :action="route('panel.customers.update', $customer)"
                                            trigger="Editar">
                                            <x-panel.field name="name" label="Nombre" required :value="$customer->name" />
                                            <div class="grid grid-cols-2 gap-3">
                                                <x-panel.field name="cedula" label="Cédula" :value="$customer->cedula" />
                                                <x-panel.field name="phone" label="Teléfono" :value="$customer->phone" />
                                            </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <x-panel.field name="tax_id" label="RNC (opcional)" :value="$customer->tax_id" />
                                                <x-panel.field name="email" label="Correo" type="email" :value="$customer->email" />
                                            </div>
                                            <x-panel.field name="address" label="Dirección" :value="$customer->address" />
                                            <div>
                                                <label class="bmos-field-label">Notas</label>
                                                <textarea name="notes" rows="3" class="bmos-input" maxlength="2000"
                                                          placeholder="Paga los viernes, pide fiado…">{{ old('notes', $customer->notes) }}</textarea>
                                            </div>
                                        </x-panel.edit-modal>

                                        {{-- Archivar: reversible, no rompe nada, y es lo que el
                                             diálogo de antes ya prometía. --}}
                                        <x-panel.confirm-action
                                            :action="route('panel.customers.toggle', $customer)"
                                            method="POST"
                                            tone="neutral"
                                            :title="$customer->is_active ? '¿Archivar a «'.$customer->name.'»?' : '¿Reactivar a «'.$customer->name.'»?'"
                                            :message="$customer->is_active
                                                ? 'Deja de aparecer al vender y en el listado.'
                                                : 'Vuelve a aparecer al vender y en el listado.'"
                                            note="Sus compras, su saldo y sus documentos se conservan tal cual. Puedes deshacerlo cuando quieras."
                                            :confirm="$customer->is_active ? 'Archivar' : 'Reactivar'"
                                            :tooltip="$customer->is_active ? 'Archivar' : 'Reactivar'"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-amber-50 hover:text-amber-600">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m6 4.125 2.25 2.25m0 0 2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                                        </x-panel.confirm-action>

                                        {{-- Eliminar de verdad. El servidor se niega si el cliente
                                             tiene ventas, facturas, entregas o préstamos. --}}
                                        <x-panel.confirm-action
                                            :action="route('panel.customers.destroy', $customer)"
                                            title="¿Eliminar a «{{ $customer->name }}»?"
                                            message="Solo se puede eliminar a quien no tenga ninguna venta, factura, entrega ni préstamo."
                                            note="Si tiene algo de eso, archívalo: deja de aparecer al vender y no se pierde nada."
                                            irreversible
                                            tooltip="Eliminar"
                                            class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916"/></svg>
                                        </x-panel.confirm-action>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="bmos-empty">
                                @if (request()->filled('q'))
                                    Ningún cliente coincide con esa búsqueda.
                                @elseif ($estado === 'archivados')
                                    No tienes clientes archivados.
                                @else
                                    Todavía no tienes clientes. Crea el primero con «Nuevo cliente».
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $customers->links() }}</div>
    </div>
</x-layouts.admin>
