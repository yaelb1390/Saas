            {{--
                ------------------------------------------------------------------ Empresas

                Una fila por empresa con su estado. Es lo que faltaba: todo lo demás de esta pantalla
                responde «¿cómo está la plataforma?», y para saber cómo le va a un cliente concreto
                había que ir a mirar sus datos uno por uno.

                La ficha (Fase 7) agrupa esas mismas señales —y otras nuevas, cruzadas con el resto
                del monitoreo: errores, incidentes, jobs, rendimiento— en seis dominios, cada uno
                HEALTHY/WARNING/CRITICAL. Van como etiquetas y no como columnas: casi siempre están
                vacías, y una fila de columnas en guiones no dice nada. Así solo se ve lo que pasa.
            --}}
            <div>
                <div class="bmos-tabla-envoltura">
                    <table class="bmos-table">
                        <thead>
                            <tr>
                                <th>Estado</th>
                                <th>Empresa</th>
                                <th>Plan</th>
                                <th>Última venta</th>
                                <th>Último acceso</th>
                                <th>Qué le pasa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($salud_empresas as $e)
                                @php
                                    $ficha = $fichas[$e['id']] ?? null;
                                    $estadoGeneral = $ficha?->estadoGeneral() ?? 'healthy';
                                    $tonoEstado = ['critical' => 'badge-red', 'warning' => 'badge-amber', 'healthy' => 'badge-green'][$estadoGeneral];
                                    $etiquetaEstado = ['critical' => 'Crítica', 'warning' => 'Con avisos', 'healthy' => 'Sana'][$estadoGeneral];
                                @endphp
                                <tr>
                                    <td><span class="bmos-badge {{ $tonoEstado }}">{{ $etiquetaEstado }}</span></td>
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
                                        @forelse ($ficha?->problemas() ?? [] as $problema)
                                            <span class="bmos-badge {{ $problema->severidad === 'critical' ? 'badge-red' : 'badge-amber' }} mb-1 mr-1">
                                                {{ $problema->texto }}
                                            </span>
                                        @empty
                                            <span class="text-sm text-emerald-600">Todo en orden</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach

                            @if (count($salud_empresas) === 0)
                                <tr><td colspan="6" class="py-6 text-center text-sm text-slate-400">No hay empresas todavía.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
