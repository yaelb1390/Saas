@php
    $filtroConcepto = request('concepto');
    $filtroCuenta = request('cuenta');
    $hayFiltro = $filtroConcepto || $filtroCuenta || request('q');
    $mayor = $porConcepto->max('total') ?: 1;
@endphp

<x-layouts.admin title="Gastos" heading="Gastos"
                 subheading="En qué se va el dinero del negocio">
    <div>
        {{-- Rango de fechas. Por defecto el mes en curso, que es como la gente piensa sus gastos. --}}
        <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
            <div>
                <label class="bmos-field-label">Desde</label>
                <input type="date" name="desde" value="{{ $desde->toDateString() }}" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label">Hasta</label>
                <input type="date" name="hasta" value="{{ $hasta->toDateString() }}" class="bmos-input">
            </div>
            <div>
                <label class="bmos-field-label">Concepto</label>
                <select name="concepto" class="bmos-input">
                    <option value="">Todos</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected($filtroConcepto == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="bmos-field-label">Cuenta</label>
                <select name="cuenta" class="bmos-input">
                    <option value="">Todas</option>
                    @foreach ($accounts as $a)
                        <option value="{{ $a->id }}" @selected($filtroCuenta == $a->id)>{{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bmos-btn bmos-btn-primary">Ver</button>
            @if ($hayFiltro)
                <a href="{{ route('panel.expenses') }}" class="bmos-btn bmos-btn-ghost text-xs">Quitar filtros</a>
            @endif
        </form>

        {{-- El total del período y el desglose por concepto.

             Esto es la pantalla; la tabla de abajo es el detalle. Una lista de gastos sin agrupar no
             contesta «¿en qué se me va el dinero?», que es la única pregunta que se le hace a esta
             sección. --}}
        <div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="bmos-card bmos-card-pad">
                <p class="bmos-stat-label">Total del período</p>
                <p class="mt-1 text-3xl font-bold text-rose-600">{{ money($total) }}</p>
                <p class="mt-1 text-xs text-slate-400">
                    {{ $desde->format('d/m/Y') }} — {{ $hasta->format('d/m/Y') }}
                    · {{ $expenses->total() }} {{ $expenses->total() === 1 ? 'gasto' : 'gastos' }}
                </p>

                @if ($sesionAbierta && $hayCuentaEfectivo)
                    <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Hay un <b>turno de caja abierto</b>. Lo que pagues desde una cuenta de efectivo
                        se descontará también del arqueo.
                    </p>
                @endif
            </div>

            <div class="bmos-card bmos-card-pad lg:col-span-2">
                <p class="font-semibold text-slate-800">Por concepto</p>
                @if ($porConcepto->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">Sin gastos en este período.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($porConcepto as $fila)
                            @php
                                // Barra proporcional al concepto que más pesa, no al total: con un
                                // concepto que se lleva el 80 %, medir sobre el total dejaría a los
                                // demás como líneas invisibles y no se podrían comparar entre sí.
                                $ancho = max(2, round(((float) $fila->total / (float) $mayor) * 100));
                                $parte = (float) $total > 0 ? ((float) $fila->total / (float) $total) * 100 : 0;
                            @endphp
                            <a href="{{ route('panel.expenses', array_merge(request()->query(), ['concepto' => $fila->expense_category_id])) }}"
                               class="block rounded-lg px-2 py-1.5 transition hover:bg-slate-50">
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-700">{{ $fila->category?->name ?? 'Sin concepto' }}</span>
                                    <span class="shrink-0 font-semibold text-slate-800">
                                        {{ money($fila->total) }}
                                        <span class="ml-1 text-xs font-normal text-slate-400">{{ number_format($parte, 0) }}%</span>
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-rose-400" style="width: {{ $ancho }}%"></div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{--
            LA TABLA DINÁMICA.

            Una lista de gastos no contesta la única pregunta que se le hace a esta pantalla: «¿en qué
            se me va el dinero, y va a más o a menos?». Para eso hay que cruzar dos ejes —en qué se
            gasta contra cuándo—, y eso es una tabla dinámica.

            Se pinta como una hoja de cálculo a propósito: rejilla densa, cifras a la derecha, cabecera
            y primera columna clavadas al desplazar. Quien lleva las cuentas de un negocio ya sabe leer
            eso; no hay que enseñarle una forma nueva.

            LA DINÁMICA NO LLEVA BOTONES. Una celda es una suma de gastos, no un gasto, así que no se
            puede editar ni anular desde aquí. Por eso hay un conmutador: el resumen contesta «en qué»
            y el detalle deja tocar cada apunte.
        --}}
        <div class="gasto-vistas">
            <a href="{{ request()->fullUrlWithQuery(['vista' => 'resumen']) }}"
               class="gasto-vista {{ $vista === 'resumen' ? 'is-activa' : '' }}">Resumen</a>
            <a href="{{ request()->fullUrlWithQuery(['vista' => 'detalle']) }}"
               class="gasto-vista {{ $vista === 'detalle' ? 'is-activa' : '' }}">Detalle</a>
        </div>

        @if ($vista === 'resumen')
            {{--
                EL GRÁFICO, con tres formas. Cada una contesta una pregunta distinta; no son tres
                maneras de pintar lo mismo, y por eso se eligen por la pregunta y no por el tipo:

                  · «En qué se va»    → barras ordenadas. La magnitud se lee por LONGITUD, así que
                                        van todas del mismo color: trece colores no dirían nada que
                                        la longitud no diga ya, y harían la lista ilegible.
                  · «Cómo evoluciona» → una línea. Serie única, sin leyenda: el título ya la nombra.
                  · «Cómo se reparte» → barras apiladas en el tiempo, que es lo único que cruza las
                                        dos preguntas a la vez.

                LAS SERIES SE CORTAN EN SIETE (seis y «Otros»). Con trece categorías, dos tonos
                vecinos son indistinguibles para quien no distingue bien el color — y para el resto
                tampoco ayudan. La paleta está comprobada contra el fondo de esta tarjeta, y las
                cifras exactas viven en la tabla de abajo, que es la vista de tabla que acompaña al
                gráfico.
            --}}
            @php $datosGrafico = $dinamica->paraGrafico(); @endphp

            <div class="bmos-card mb-6 overflow-hidden"
                 x-data="graficoDeGastos(@js($datosGrafico))" x-init="pintar()">
                <div class="gasto-din-cab">
                    <p class="font-semibold text-slate-800">Gráfico</p>

                    <div class="gasto-formas" role="group" aria-label="Tipo de gráfico">
                        <button type="button" @click="cambiar('ranking')"
                                :class="forma === 'ranking' && 'is-activa'"
                                :aria-pressed="forma === 'ranking'" class="gasto-forma">En qué se va</button>
                        <button type="button" @click="cambiar('evolucion')"
                                :class="forma === 'evolucion' && 'is-activa'"
                                :aria-pressed="forma === 'evolucion'" class="gasto-forma">Cómo evoluciona</button>
                        <button type="button" @click="cambiar('composicion')"
                                :class="forma === 'composicion' && 'is-activa'"
                                :aria-pressed="forma === 'composicion'" class="gasto-forma">Cómo se reparte</button>
                    </div>
                </div>

                @if ($dinamica->estaVacia())
                    <p class="bmos-empty">Sin gastos que dibujar en este período.</p>
                @else
                    <div class="gasto-lienzo">
                        <canvas x-ref="lienzo" aria-label="Gráfico de gastos"></canvas>
                    </div>
                @endif
            </div>

            <div class="bmos-card mb-6 overflow-hidden">
                <div class="gasto-din-cab">
                    <p class="font-semibold text-slate-800">Tabla dinámica</p>

                    {{-- Los dos ejes. Se envían al cambiarlos, conservando el resto de filtros: hacer
                         que además haya que pulsar «Ver» convierte una exploración en un formulario. --}}
                    <form method="GET" class="gasto-din-ejes">
                        @foreach (request()->except(['filas', 'columnas', 'page']) as $clave => $valor)
                            <input type="hidden" name="{{ $clave }}" value="{{ $valor }}">
                        @endforeach

                        <label class="gasto-din-eje">
                            <span>Filas</span>
                            <select name="filas" onchange="this.form.submit()" class="bmos-input">
                                <option value="categoria" @selected($ejeFilas === 'categoria')>Categoría</option>
                                <option value="concepto" @selected($ejeFilas === 'concepto')>Concepto</option>
                                <option value="cuenta" @selected($ejeFilas === 'cuenta')>Cuenta</option>
                                <option value="proveedor" @selected($ejeFilas === 'proveedor')>Proveedor</option>
                            </select>
                        </label>

                        <label class="gasto-din-eje">
                            <span>Columnas</span>
                            <select name="columnas" onchange="this.form.submit()" class="bmos-input">
                                <option value="" @selected($ejeColumnas === '')>Automático</option>
                                <option value="dia" @selected($ejeColumnas === 'dia')>Día</option>
                                <option value="semana" @selected($ejeColumnas === 'semana')>Semana</option>
                                <option value="mes" @selected($ejeColumnas === 'mes')>Mes</option>
                            </select>
                        </label>
                    </form>
                </div>

                @if ($dinamica->estaVacia())
                    <p class="bmos-empty">Sin gastos entre el {{ $desde->format('d/m/Y') }} y el {{ $hasta->format('d/m/Y') }}.</p>
                @else
                    <div class="gasto-din-marco">
                        <table class="gasto-din">
                            <thead>
                                <tr>
                                    <th class="gasto-din-esquina">
                                        {{ ['categoria' => 'Categoría', 'concepto' => 'Concepto', 'cuenta' => 'Cuenta', 'proveedor' => 'Proveedor'][$ejeFilas] ?? 'Categoría' }}
                                    </th>
                                    @foreach ($dinamica->columnas as $columna)
                                        <th class="gasto-din-num">{{ $columna['rotulo'] }}</th>
                                    @endforeach
                                    <th class="gasto-din-num gasto-din-cierre">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($dinamica->filas as $fila)
                                    <tr>
                                        <th scope="row" class="gasto-din-fila" data-tono="{{ $fila['tono'] }}">
                                            {{ $fila['rotulo'] }}
                                        </th>
                                        @foreach ($dinamica->columnas as $columna)
                                            @php $celda = $dinamica->celda($fila, $columna['clave']); @endphp
                                            {{-- Un guion y no «0.00»: cero sería «se gastó y salió cero»,
                                                 que no pasa nunca. El hueco es el dato. --}}
                                            <td class="gasto-din-num {{ $celda === null ? 'es-vacia' : '' }}">
                                                {{ $celda === null ? '—' : number_format((float) $celda, 2) }}
                                            </td>
                                        @endforeach
                                        <td class="gasto-din-num gasto-din-cierre">{{ number_format((float) $fila['total'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row" class="gasto-din-fila">Total</th>
                                    @foreach ($dinamica->columnas as $columna)
                                        @php $suma = $dinamica->totales[$columna['clave']] ?? '0.00'; @endphp
                                        <td class="gasto-din-num {{ bccomp($suma, '0', 2) === 0 ? 'es-vacia' : '' }}">
                                            {{ bccomp($suma, '0', 2) === 0 ? '—' : number_format((float) $suma, 2) }}
                                        </td>
                                    @endforeach
                                    <td class="gasto-din-num gasto-din-cierre">{{ number_format((float) $dinamica->granTotal, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        @if ($vista === 'detalle')
        <div class="bmos-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
                <p class="font-semibold text-slate-800">Detalle</p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-panel.search-bar placeholder="Buscar por descripción, código o proveedor..." />
                    @can('finance.manage')
                        <x-panel.create-modal title="Nuevo gasto" label="Nuevo gasto" form="expense_create"
                                              :action="route('panel.expenses.store')" width="max-w-2xl">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">¿En qué? <span class="text-rose-500">*</span></label>
                                    <input type="text" name="description" value="{{ old('description') }}" class="bmos-input"
                                           placeholder="Factura de luz de agosto" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Concepto <span class="text-rose-500">*</span></label>
                                    <select name="expense_category_id" class="bmos-input" required>
                                        @foreach ($categories as $c)
                                            <option value="{{ $c->id }}" @selected(old('expense_category_id') == $c->id)>{{ $c->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Monto (RD$) <span class="text-rose-500">*</span></label>
                                    <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">¿De qué cuenta sale? <span class="text-rose-500">*</span></label>
                                    <select name="account_id" class="bmos-input" required>
                                        @foreach ($accounts as $a)
                                            <option value="{{ $a->id }}" @selected(old('account_id', $accounts->firstWhere('is_default', true)?->id) == $a->id)>
                                                {{ $a->name }} ({{ $a->type->label() }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Fecha de pago <span class="text-rose-500">*</span></label>
                                    <input type="date" name="paid_at" value="{{ old('paid_at', now()->toDateString()) }}"
                                           max="{{ now()->toDateString() }}" class="bmos-input" required>
                                </div>
                                <div>
                                    <label class="bmos-field-label">Proveedor (opcional)</label>
                                    <select name="supplier_id" class="bmos-input">
                                        <option value="">— Ninguno —</option>
                                        @foreach ($suppliers as $s)
                                            <option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="bmos-field-label">O a quién se le pagó</label>
                                    <input type="text" name="supplier_name" value="{{ old('supplier_name') }}" class="bmos-input"
                                           placeholder="Edenorte, el mensajero...">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">Referencia (opcional)</label>
                                    <input type="text" name="reference" value="{{ old('reference') }}" class="bmos-input"
                                           placeholder="Nº de cheque, transferencia o factura">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="bmos-field-label">Notas</label>
                                    <textarea name="notes" rows="2" class="bmos-input">{{ old('notes') }}</textarea>
                                </div>
                            </div>

                            @if ($sesionAbierta)
                                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                    Con la caja abierta, si eliges una cuenta de <b>efectivo</b> el gasto se
                                    descuenta también del arqueo del turno.
                                </p>
                            @endif
                        </x-panel.create-modal>
                    @endcan
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="bmos-table">
                    <thead>
                        <tr>
                            <th>Código</th><th>Fecha</th><th>Descripción</th><th>Concepto</th>
                            <th>A quién</th><th>Cuenta</th><th class="text-right">Monto</th>
                            @can('finance.manage')<th class="text-right">Anular</th>@endcan
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expenses as $gasto)
                            <tr>
                                <td class="font-mono text-xs text-slate-500">{{ $gasto->code }}</td>
                                <td class="text-xs text-slate-500">{{ $gasto->paid_at?->format('d/m/Y') }}</td>
                                <td class="font-medium text-slate-800">
                                    {{ $gasto->description }}
                                    @if ($gasto->reference)
                                        <span class="block text-xs text-slate-400">Ref. {{ $gasto->reference }}</span>
                                    @endif
                                </td>
                                <td><span class="bmos-badge badge-gray">{{ $gasto->category?->name ?? '—' }}</span></td>
                                <td class="text-sm text-slate-600">{{ $gasto->aQuien() }}</td>
                                <td class="text-sm text-slate-600">{{ $gasto->account?->name ?? '—' }}</td>
                                <td class="text-right font-semibold text-rose-600">−{{ number_format((float) $gasto->amount, 2) }}</td>
                                @can('finance.manage')
                                    <td>
                                        <div class="flex items-center justify-end">
                                            <x-panel.confirm-action
                                                :action="route('panel.expenses.destroy', $gasto)"
                                                title="¿Anular el gasto {{ $gasto->code }}?"
                                                message="Se devuelven {{ money($gasto->amount) }} al saldo de «{{ $gasto->account?->name }}»."
                                                :note="$gasto->cashMovement()->exists()
                                                    ? 'Salió del cajón, así que también vuelve al arqueo del turno. Si ese turno ya se cerró, no se podrá anular.'
                                                    : 'El gasto deja de contar en los informes por concepto.'"
                                                tooltip="Anular"
                                                confirm="Anular el gasto"
                                                class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" style="width:1.15rem;height:1.15rem"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            </x-panel.confirm-action>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @empty
                            <tr><td colspan="8" class="bmos-empty">
                                @if ($hayFiltro)
                                    Ningún gasto coincide con los filtros.
                                    <a href="{{ route('panel.expenses') }}" class="text-indigo-600 hover:underline">Quitarlos</a>
                                @else
                                    Sin gastos entre el {{ $desde->format('d/m/Y') }} y el {{ $hasta->format('d/m/Y') }}.
                                    Anota el primero con «Nuevo gasto».
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($expenses->hasPages())
                <div class="border-t border-slate-100 p-4">{{ $expenses->links() }}</div>
            @endif
        </div>
        @endif

        {{-- Conceptos: se administran aquí abajo y no en otra pantalla, porque solo se tocan cuando
             falta uno al anotar un gasto. --}}
        @can('finance.manage')
            <div class="mt-6 bmos-card overflow-hidden" x-data="{ abierto: false }">
                <button type="button" @click="abierto = !abierto"
                        class="flex w-full items-center justify-between p-4 text-left hover:bg-slate-50">
                    <span class="font-semibold text-slate-800">Conceptos de gasto</span>
                    <span class="text-xs text-slate-400" x-text="abierto ? 'Ocultar' : `Administrar (${{{ $categories->count() }}})`"></span>
                </button>

                <div x-show="abierto" x-cloak class="border-t border-slate-100 p-4">
                    <form method="POST" action="{{ route('panel.expense-categories.store') }}" class="mb-4 flex flex-wrap gap-2">
                        @csrf
                        <input type="text" name="name" class="bmos-input max-w-xs" placeholder="Nombre del concepto nuevo" required>
                        <button type="submit" class="bmos-btn bmos-btn-primary">Añadir</button>
                    </form>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($categories as $c)
                            <form method="POST" action="{{ route('panel.expense-categories.update', $c) }}"
                                  class="flex items-center gap-2 rounded-lg border border-slate-200 p-2">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $c->name }}" class="bmos-input flex-1 text-sm">
                                <label class="flex shrink-0 items-center gap-1 text-xs text-slate-500" title="Desmarcar lo retira de los formularios sin tocar los gastos que ya lo usan">
                                    <input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-slate-300 text-indigo-600">
                                    Activo
                                </label>
                                <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Guardar</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        @endcan
    </div>

        {{--
            DATOS DE PRUEBA. Solo fuera de producción.

            No es pudor: sembrar cincuenta apuntes en la contabilidad de un negocio de verdad es
            exactamente el tipo de botón que nadie quiere descubrir que existía. Aquí ni se pinta, y
            el servidor además responde 404 — esconderlo nunca ha sido protegerlo.

            Y NO HAY UN «BORRAR TODO», aunque se pidiera así. El borrado filtra por el prefijo
            «DEMO-» del código, así que no puede llevarse por delante un gasto real ni equivocándose.
            En este módulo hasta anular es un borrado lógico para no perder el historial; un botón
            capaz de vaciarlo sería una contradicción con el propósito de la pantalla.
        --}}
        @if (! app()->isProduction())
            <div class="gasto-demo">
                <div class="min-w-0">
                    <p class="gasto-demo-titulo">Datos de prueba</p>
                    <p class="gasto-demo-nota">
                        Solo en desarrollo. Los de prueba llevan código <b>DEMO-</b> y el borrado únicamente
                        toca esos: tus gastos reales no se pueden perder desde aquí.
                    </p>
                </div>

                <div class="gasto-demo-botones">
                    <form method="POST" action="{{ route('panel.expenses.demo') }}">
                        @csrf
                        <button type="submit" class="bmos-btn bmos-btn-suave">Generar 50 gastos</button>
                    </form>

                    <form method="POST" action="{{ route('panel.expenses.demo.destroy') }}" id="borrar-demo">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="bmos-btn bmos-btn-suave gasto-demo-borrar"
                                @click="window.confirmarAccion({
                                    titulo: 'Borrar los gastos de prueba',
                                    mensaje: 'Se borran solo los que tienen código DEMO-. Los gastos reales no se tocan.',
                                    confirmar: 'Borrar los de prueba',
                                    formulario: 'borrar-demo',
                                })">
                            Borrar los de prueba
                        </button>
                    </form>
                </div>
            </div>
        @endif


    <script>
        /*
         * El gráfico de gastos. Chart.js se carga BAJO DEMANDA con `window.loadChart()`, que es como
         * lo hace el resto del panel: son ciento y pico kilobytes que solo usan tres pantallas.
         */
        function graficoDeGastos(datos) {
            return {
                datos,
                forma: 'ranking',
                grafico: null,

                /*
                 * La paleta. Comprobada con el validador contra el fondo blanco de la tarjeta: pasa
                 * la banda de luminosidad, el mínimo de croma, la separación para daltonismo y el
                 * mínimo de visión normal.
                 *
                 * Van EN ORDEN FIJO, nunca cicladas: el color sigue a la categoría, no a su puesto
                 * en el ranking. Si el color se asignara por posición, filtrar un mes repintaría
                 * las que quedan y «Alimentos» cambiaría de color al cambiar el filtro.
                 */
                paleta: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7'],

                // El gasto en una sola tinta: la magnitud la lleva la longitud de la barra.
                tinta: '#e11d48',

                rd(n) {
                    return 'RD$ ' + Number(n).toLocaleString('es-DO', {
                        minimumFractionDigits: 2, maximumFractionDigits: 2,
                    });
                },

                cambiar(forma) {
                    if (this.forma === forma) return;
                    this.forma = forma;
                    this.pintar();
                },

                async pintar() {
                    if (!this.$refs.lienzo) return;

                    const Chart = await window.loadChart();

                    if (this.grafico) this.grafico.destroy();

                    this.grafico = new Chart(this.$refs.lienzo, this.configuracion(Chart));
                },

                configuracion() {
                    const rd = this.rd;

                    /* Rejilla y ejes RECESIVOS: el dato es la barra, no la cuadrícula. */
                    const ejes = {
                        x: { grid: { display: false }, border: { display: false },
                             ticks: { color: '#94a3b8', font: { size: 11 } } },
                        y: { grid: { color: '#f1f5f9' }, border: { display: false },
                             ticks: { color: '#94a3b8', font: { size: 11 },
                                      callback: (v) => Number(v).toLocaleString('es-DO') } },
                    };

                    const comun = {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            tooltip: {
                                backgroundColor: '#1e2230',
                                padding: 10,
                                callbacks: { label: (c) => ' ' + c.dataset.label + ': ' + rd(c.parsed.y ?? c.parsed.x) },
                            },
                        },
                    };

                    if (this.forma === 'ranking') {
                        return {
                            type: 'bar',
                            data: {
                                labels: this.datos.ranking.map((f) => f.nombre),
                                datasets: [{
                                    label: 'Gasto',
                                    data: this.datos.ranking.map((f) => f.total),
                                    backgroundColor: this.tinta,
                                    // Punta redondeada y anclada a la base, como el resto del panel.
                                    borderRadius: 4,
                                    borderSkipped: 'start',
                                    barThickness: 14,
                                }],
                            },
                            options: {
                                ...comun,
                                // Horizontal: los nombres de categoría son largos y en vertical se
                                // giran hasta no leerse.
                                indexAxis: 'y',
                                scales: {
                                    x: { ...ejes.y, beginAtZero: true },
                                    y: { grid: { display: false }, border: { display: false },
                                         ticks: { color: '#475569', font: { size: 11 } } },
                                },
                                // Serie única: la leyenda repetiría el título de la tarjeta.
                                plugins: { ...comun.plugins, legend: { display: false } },
                            },
                        };
                    }

                    if (this.forma === 'evolucion') {
                        const total = this.datos.columnas.map((_, i) =>
                            this.datos.series.reduce((suma, s) => suma + (s.datos[i] || 0), 0));

                        return {
                            type: 'line',
                            data: {
                                labels: this.datos.columnas,
                                datasets: [{
                                    label: 'Gasto total',
                                    data: total,
                                    borderColor: this.tinta,
                                    backgroundColor: 'rgba(225, 29, 72, 0.08)',
                                    borderWidth: 2,
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
                                    pointBackgroundColor: this.tinta,
                                    // Anillo del color del fondo, para que dos puntos juntos no se
                                    // fundan en una mancha.
                                    pointBorderColor: '#fff',
                                    pointBorderWidth: 2,
                                    fill: true,
                                    tension: 0.25,
                                }],
                            },
                            options: { ...comun, scales: { ...ejes, y: { ...ejes.y, beginAtZero: true } },
                                       plugins: { ...comun.plugins, legend: { display: false } } },
                        };
                    }

                    return {
                        type: 'bar',
                        data: {
                            labels: this.datos.columnas,
                            datasets: this.datos.series.map((s, i) => ({
                                label: s.nombre,
                                data: s.datos,
                                backgroundColor: this.paleta[i % this.paleta.length],
                                borderRadius: 3,
                                // Dos píxeles del color del fondo entre segmentos: sin ese hueco,
                                // dos tramos contiguos se leen como uno solo.
                                borderColor: '#fff',
                                borderWidth: { top: 2, right: 0, bottom: 0, left: 0 },
                            })),
                        },
                        options: {
                            ...comun,
                            scales: { x: { ...ejes.x, stacked: true },
                                      y: { ...ejes.y, stacked: true, beginAtZero: true } },
                            plugins: {
                                ...comun.plugins,
                                // Con varias series la leyenda es obligatoria: la identidad no puede
                                // depender solo del color.
                                legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10,
                                          usePointStyle: true, pointStyle: 'circle',
                                          color: '#475569', font: { size: 11 }, padding: 14 } },
                            },
                        },
                    };
                },
            };
        }
    </script>

</x-layouts.admin>
