{{--
    Impresora por módulo: Ventas → la térmica de la caja, Facturación → la A4, etc.

    Cada fila es su propio formulario que se envía solo al elegir — el mismo patrón que el filtro por
    producto de «Unidades en serie»—. Sin esto, una empresa con varias impresoras tendría que elegir
    a mano cuál usar cada vez que imprime, en vez de una vez aquí para siempre.
--}}
<div class="bmos-card overflow-hidden max-w-2xl">
    <div class="border-b border-slate-100 p-4">
        <p class="font-semibold text-slate-800">Impresora por módulo</p>
        <p class="mt-0.5 text-xs text-slate-500">Sin asignar, se usa tu impresora predeterminada o el diálogo del navegador.</p>
    </div>

    @if ($printers->isEmpty())
        <p class="bmos-empty">Registra al menos una impresora para poder asignarla a un módulo.</p>
    @else
        <div class="divide-y divide-slate-100">
            @foreach ($printableModules as $clave => $etiqueta)
                <form method="POST" action="{{ route('panel.printing.modules.assign') }}"
                      class="flex items-center justify-between gap-3 px-4 py-3">
                    @csrf
                    <input type="hidden" name="module" value="{{ $clave }}">
                    <span class="text-sm font-medium text-slate-700">{{ $etiqueta }}</span>
                    <select name="printer_id" onchange="this.form.submit()" class="bmos-input max-w-56">
                        <option value="">— Sin asignar —</option>
                        @foreach ($printers as $printer)
                            <option value="{{ $printer->id }}" @selected(($modulesMap[$clave] ?? null) === $printer->id)>{{ $printer->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endforeach
        </div>
    @endif
</div>
