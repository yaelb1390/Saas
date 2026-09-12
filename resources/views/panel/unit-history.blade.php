@use('App\Modules\Inventory\Models\ProductUnit')

{{--
    Consulta de una unidad por su serie.

    Es la pantalla que se abre cuando un cliente vuelve con un aparato: se teclea el número de serie
    y sale qué es, dónde está y a quién se le vendió. Sin esto, serializar el inventario no serviría
    de nada — la razón de guardar cada serie es poder contestar esta pregunta seis meses después.
--}}
<x-layouts.admin title="Consulta por serie" heading="Consulta por serie"
                 subheading="Teclea el número de serie de un equipo y mira su historia">

    <div class="max-w-2xl">
        {{-- El buscador. Un GET a propósito: la consulta queda en la URL y se puede compartir o
             recargar sin volver a teclear. --}}
        <form method="GET" action="{{ route('panel.serial.history') }}" class="mb-6 flex gap-2">
            <input type="text" name="serie" value="{{ $serie }}" autofocus autocomplete="off"
                   placeholder="Escanea o teclea el número de serie / IMEI…"
                   class="bmos-input font-mono flex-1">
            <button type="submit" class="bmos-btn bmos-btn-primary">Buscar</button>
        </form>

        @if ($serie !== '' && $ficha === null)
            <div class="bmos-card bmos-card-pad">
                <p class="font-semibold text-slate-800">No hay ninguna unidad con esa serie.</p>
                <p class="mt-1 text-sm text-slate-500">
                    Revisa el número, o puede que se diera de alta en otra empresa. La serie es
                    «<span class="font-mono">{{ $serie }}</span>».
                </p>
            </div>
        @elseif ($ficha !== null)
            @php $u = $ficha->unidad; @endphp

            <div class="bmos-card overflow-hidden">
                {{-- La cabecera: qué es y en qué estado está. --}}
                <div class="border-b border-slate-100 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-lg font-semibold text-slate-800">{{ $u->product?->name ?? 'Producto' }}</p>
                            <p class="mt-0.5 font-mono text-sm text-slate-500">{{ $u->serial }}</p>
                        </div>
                        @php
                            $tono = match ($u->status) {
                                ProductUnit::DISPONIBLE => 'badge-emerald',
                                ProductUnit::VENDIDA => 'badge-slate',
                                ProductUnit::DEVUELTA => 'badge-amber',
                                default => 'badge-blue',
                            };
                            $texto = match ($u->status) {
                                ProductUnit::DISPONIBLE => 'Disponible',
                                ProductUnit::VENDIDA => 'Vendida',
                                ProductUnit::DEVUELTA => 'Devuelta',
                                default => 'Reservada',
                            };
                        @endphp
                        <span class="bmos-badge shrink-0 {{ $tono }}">{{ $texto }}</span>
                    </div>

                    {{-- Los datos de la unidad, los que tenga. --}}
                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        @if ($u->condition)
                            <span class="text-slate-500">Condición: <b class="text-slate-700">{{ ucfirst($u->condition) }}</b></span>
                        @endif
                        @if ($u->color)
                            <span class="text-slate-500">Color: <b class="text-slate-700">{{ $u->color }}</b></span>
                        @endif
                        @if ($u->warehouse)
                            <span class="text-slate-500">Almacén: <b class="text-slate-700">{{ $u->warehouse->name }}</b></span>
                        @endif
                    </div>
                </div>

                {{-- La historia: qué le ha pasado, en orden. --}}
                <div class="p-5">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Historia</p>
                    <ol class="space-y-3">
                        @foreach ($ficha->linea() as $evento)
                            <li class="flex gap-3">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-indigo-400"></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-800">{{ $evento['hecho'] }}</p>
                                    <p class="text-xs text-slate-500">
                                        @if ($evento['cuando']){{ $evento['cuando'] }}@endif
                                        @if ($evento['cuando'] && $evento['detalle']) · @endif
                                        @if ($evento['detalle']){{ $evento['detalle'] }}@endif
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        @endif
    </div>

    @include('partials.toast')
</x-layouts.admin>
