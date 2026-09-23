            {{--
                ------------------------------------------------------------------ Empresas

                Una fila por empresa con su estado. Es lo que faltaba: todo lo demás de esta pantalla
                responde «¿cómo está la plataforma?», y para saber cómo le va a un cliente concreto
                había que ir a mirar sus datos uno por uno.

                Las señales van como etiquetas y no como columnas: son ocho, casi siempre están
                vacías, y ocho columnas de guiones no dicen nada. Así solo se ve lo que pasa.
            --}}
            <div>
                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th>Plan</th>
                                <th>Última venta</th>
                                <th>Último acceso</th>
                                <th>Qué le pasa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salud_empresas as $e)
                                <tr>
                                    <td>
                                        <span class="font-medium text-slate-700">{{ $e['nombre'] }}</span>
                                        @unless ($e['activa'])
                                            <span class="bmos-badge badge-rose ml-1">Inactiva</span>
                                        @endunless
                                        <span class="block text-xs text-slate-400">
                                            {{ $e['usuarios'] }}
                                            {{ $e['usuarios'] === 1 ? 'usuario' : 'usuarios' }}@if ($e['limite_usuarios']) de {{ $e['limite_usuarios'] }}@endif
                                            ·
                                            {{ $e['sucursales'] }}
                                            {{ $e['sucursales'] === 1 ? 'sucursal' : 'sucursales' }}
                                        </span>
                                    </td>
                                    <td class="text-sm text-slate-500">{{ $e['plan'] ?? '—' }}</td>
                                    {{-- La fecha Y el «hace cuánto»: la fecha sola obliga a contar
                                         días de cabeza, que es justo lo que se quiere saber. --}}
                                    <td class="text-sm">
                                        @if ($e['ultima_venta'])
                                            <span class="text-slate-600">{{ $e['ultima_venta']->format('d/m/Y') }}</span>
                                            <span class="block text-xs text-slate-400">{{ $e['ultima_venta']->diffForHumans() }}</span>
                                        @else
                                            <span class="text-slate-400">nunca</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">
                                        @if ($e['ultimo_acceso'])
                                            <span class="text-slate-600">{{ $e['ultimo_acceso']->format('d/m/Y') }}</span>
                                            <span class="block text-xs text-slate-400">{{ $e['ultimo_acceso']->diffForHumans() }}</span>
                                        @else
                                            <span class="text-slate-400">sin registro</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            // Lo que IMPIDE vender primero, y en rojo. Lo demás informa.
                                            $avisos = collect([
                                                ['sí' => $e['sin_almacen'], 'texto' => 'Sin almacén: no puede cobrar', 'tono' => 'badge-rose'],
                                                ['sí' => $e['sin_ncf'], 'texto' => 'Sin NCF disponible', 'tono' => 'badge-rose'],
                                                ['sí' => $e['sin_productos'], 'texto' => 'Sin productos', 'tono' => 'badge-rose'],
                                                ['sí' => $e['caja_abierta'], 'texto' => 'Caja sin cerrar', 'tono' => 'badge-amber'],
                                                ['sí' => $e['nunca_vendio'], 'texto' => 'Nunca vendió', 'tono' => 'badge-amber'],
                                                ['sí' => $e['sin_vender'], 'texto' => 'Sin vender hace semanas', 'tono' => 'badge-amber'],
                                                ['sí' => $e['pasada_de_plan'], 'texto' => 'Pasada de su plan', 'tono' => 'badge-violet'],
                                                ['sí' => $e['bot_sin_info'], 'texto' => 'Bot sin información', 'tono' => 'badge-amber'],
                                                ['sí' => $e['descuadres'] > 0, 'texto' => $e['descuadres'].' descuadre'.($e['descuadres'] === 1 ? '' : 's').' de caja', 'tono' => 'badge-gray'],
                                                ['sí' => $e['sin_precio'] > 0, 'texto' => $e['sin_precio'].' sin precio', 'tono' => 'badge-gray'],
                                            ])->where('sí', true);
                                        @endphp

                                        @forelse ($avisos as $aviso)
                                            <span class="bmos-badge {{ $aviso['tono'] }} mb-1 mr-1">{{ $aviso['texto'] }}</span>
                                        @empty
                                            <span class="text-sm text-emerald-600">Todo en orden</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach

                            @if (count($salud_empresas) === 0)
                                <tr><td colspan="5" class="py-6 text-center text-sm text-slate-400">No hay empresas todavía.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
