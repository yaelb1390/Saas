{{--
    Monitoreo de la plataforma.

    Está ordenado como se mira una consola, no como se fue escribiendo:

      1. ¿Está todo bien?  → el estado, en una línea, SIEMPRE visible.
      2. ¿Qué se quiere mirar? → cinco pestañas, y solo se calcula la que está abierta.

    LAS PESTAÑAS SON CINCO desde la Fase 1b, con «Resumen» como una más y no como un bloque fijo
    encima de las otras. Antes el pulso, la gráfica y los servicios externos se pintaban siempre,
    aunque el operador solo quisiera ver el registro; ahora eso es el contenido de LA PESTAÑA Resumen,
    y las demás no cargan lo que no van a enseñar.

    Y SON ENLACES, no botones de Alpine. Con Alpine las cuatro pestañas se traían sus datos completos
    en cada carga para poder alternar sin recargar —el problema de fondo que esta fase vino a
    arreglar—; con un enlace, el servidor solo calcula la lista de la pestaña que se pidió.

    Todo lo que se pinta es de TODAS las empresas, no de la que el operador tenga abierta.
--}}
@php
    use App\Modules\Core\Support\Tendencia;

    /*
     * Las 24 horas anteriores a las 24 que se enseñan, para poder decir si mejora o empeora.
     *
     * Fíjate en el tercer argumento, que es el que de verdad importa: más avisos o más accesos
     * fallidos es MALO aunque la flecha suba, y por eso van con false. «Sucesos» va con null —ni
     * bueno ni malo— porque que la plataforma registre más actividad no dice nada por sí solo, y
     * darle color sería inventarse un juicio que nadie ha hecho.
     *
     * Va en ESTE bloque y no junto a las tarjetas: un `use` dentro de un @php de en medio Blade lo
     * compila dentro de la función de la vista, y ahí es un error de sintaxis que tumba la pantalla.
     */
    $frenteAAyer = fn (string $clave, ?bool $subeEsBueno, string $que): ?array => Tendencia::calcular(
        (float) ($pulso['antes'][$clave] ?? 0),
        (float) $pulso['dia'][$clave],
        $subeEsBueno,
        detalle: sprintf('%s: %d en las últimas 24 h frente a %d en las 24 anteriores.',
            $que, $pulso['dia'][$clave], $pulso['antes'][$clave] ?? 0),
    );

    $tonos = ['bien' => '#059669', 'aviso' => '#d97706', 'apagado' => '#94a3b8'];
    $nivel = ['critical' => 'badge-red', 'warning' => 'badge-amber', 'info' => 'badge-blue'];

    // «App\Modules\Sales\Models\Sale» no es información para nadie.
    $enEspanol = fn (?string $clase) => match (class_basename($clase ?? '')) {
        'Sale' => 'Venta', 'Product' => 'Producto', 'Customer' => 'Cliente',
        'Invoice' => 'Factura', 'Loan' => 'Préstamo', 'Delivery' => 'Entrega',
        'Expense' => 'Gasto', 'Company' => 'Empresa', 'User' => 'Usuario',
        'Subscription' => 'Suscripción', 'Plan' => 'Plan', 'Employee' => 'Empleado',
        'CashSession' => 'Turno de caja', 'PurchaseOrder' => 'Orden de compra',
        'Supplier' => 'Proveedor', 'Category' => 'Categoría', 'Account' => 'Cuenta',
        'GoodsReceipt' => 'Entrada de mercancía', 'Opportunity' => 'Oportunidad',
        default => class_basename($clase ?? '') ?: '—',
    };

    // El color del carril de gravedad, que es lo que se recorre con la vista.
    $carril = ['critical' => '#e11d48', 'warning' => '#f59e0b', 'info' => '#cbd5e1'];

    // El enlace de cada pestaña. Solo se lleva la empresa de una pestaña a otra —es el único filtro
    // que sigue teniendo sentido al cambiar de vista—; el resto (busca, familia, estado…) es de la
    // pestaña donde se escribió y arrastrarlo a otra confundiría más de lo que ayuda.
    $urlPestana = fn (string $p): string => route('platform.monitoring', array_filter([
        'pestana' => $p,
        'empresa' => $f->empresa,
    ]));

    $pestanas = [
        'resumen' => ['etiqueta' => 'Resumen', 'num' => null],
        'registro' => ['etiqueta' => 'Registro del sistema', 'num' => null],
        'errores' => ['etiqueta' => 'Errores', 'num' => $contadores['errores_activos']],
        'incidentes' => ['etiqueta' => 'Incidentes', 'num' => $contadores['incidentes_activos']],
        'empresas' => ['etiqueta' => 'Empresas', 'num' => $contadores['empresas_con_problemas']],
        'actividad' => ['etiqueta' => 'Actividad', 'num' => null],
    ];

    // Qué parcial pinta cada pestaña. Con `@include($vista)` y no una directiva por caso: el nombre
    // ya resuelto en PHP evita escribir el nombre del parcial en un sitio donde Blade lo compilaría
    // como directiva si apareciera dentro de una cadena de texto en vez de aquí.
    $vista = match ($f->pestana) {
        'registro' => 'panel.admin.monitoring.partials.tab-registro',
        'errores' => 'panel.admin.monitoring.partials.tab-errores',
        'incidentes' => 'panel.admin.monitoring.partials.tab-incidentes',
        'empresas' => 'panel.admin.monitoring.partials.tab-empresas',
        'actividad' => 'panel.admin.monitoring.partials.tab-actividad',
        default => 'panel.admin.monitoring.partials.resumen',
    };
@endphp

<x-layouts.admin title="Monitoreo" heading="Monitoreo"
                 subheading="Qué está pasando en todas las empresas, quién lo hizo y qué se está rompiendo"
                 :wide="true">
    <div class="space-y-4">
        {{-- 1. La respuesta, antes que ningún dato. Sale de contadores REALES —totales de la base,
             nunca de una lista ya recortada para pintarse—, así que significa lo mismo mire quien
             mire, esté en la pestaña que esté. --}}
        <x-panel.estado
            :tono="$contadores['pendientes'] > 0 ? 'aviso' : 'ok'"
            :titulo="$contadores['pendientes'] > 0
                ? ($contadores['pendientes'] === 1 ? 'Una cosa pide atención' : $contadores['pendientes'].' cosas piden atención')
                : 'Todo en orden'"
            :nota="$salud['empresas_activas'].' '.($salud['empresas_activas'] === 1 ? 'empresa activa' : 'empresas activas')
                .' · '.$salud['usuarios'].' '.($salud['usuarios'] === 1 ? 'usuario' : 'usuarios')
                .' · comprobado '.now()->format('H:i')" />

        {{-- 2. El panel con pestañas. Solo se incluye la de la vista, no las cinco. --}}
        <div class="bmos-card overflow-hidden">
            <div class="border-b border-slate-100 p-3">
                <div class="bmos-pestanas" role="tablist">
                    @foreach ($pestanas as $clave => $p)
                        <a href="{{ $urlPestana($clave) }}" role="tab"
                           aria-selected="{{ $f->pestana === $clave ? 'true' : 'false' }}"
                           class="bmos-pestana">
                            {{ $p['etiqueta'] }}
                            @if ($p['num'] > 0)
                                <span class="bmos-pestana-num">{{ $p['num'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>

            @include($vista)
        </div>

        <form method="POST" action="{{ route('platform.monitoring.clean') }}" class="text-right">
            @csrf
            <button type="submit" class="bmos-btn bmos-btn-ghost text-xs">Borrar el rastro de más de un año</button>
        </form>
    </div>

    @include('panel.admin.monitoring.partials.scripts')
</x-layouts.admin>
