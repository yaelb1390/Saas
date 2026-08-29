@php
    use App\Modules\Core\Models\Company;
    use App\Modules\Core\Tenancy\CurrentCompany;
    use Illuminate\Support\Facades\Gate;

    $current = app(CurrentCompany::class);
    // Instancia compartida de la petición (con suscripción y plan ya cargados): evita que cada
    // hasModule() del menú dispare su propia consulta.
    $activeCompany = $current->model();
    $authUser = auth()->user();
    $initial = strtoupper(mb_substr($authUser?->name ?? 'U', 0, 1));
    $isSuper = (bool) $authUser?->is_super_admin;
    $companies = $isSuper ? Company::query()->orderBy('name')->get() : collect();

    // Cada entrada declara [ruta, etiqueta, icono, permiso, módulo]. El permiso es el MISMO que
    // protege su ruta; el módulo (5.º, opcional) es el que la empresa debe tener contratado. El
    // menú no decide nada: refleja lo que el usuario puede hacer y lo que su plan incluye.
    /*
     * Menú lateral, agrupado por LO QUE SE VA A HACER, no por el módulo técnico al que pertenece
     * cada pantalla. Por eso «Mostrador de repuestos» vive en «Vender» y no en «Finanzas»: es una
     * pantalla de cobro, aunque por dentro la sirva el módulo de facturación. Quien busca dónde
     * cobrar no piensa en qué módulo lo implementa.
     *
     * «Compras» (pedir mercancía al proveedor) va con Inventario porque es lo que lo repone;
     * «Compras (606)» es la declaración fiscal de esas facturas, y esa sí es de Finanzas.
     */
    $nav = [
        'Principal' => [
            ['dashboard', 'Dashboard', 'home', 'dashboard.view', null],
            ['panel.reports', 'Reportes', 'chart', 'reports.view', 'reports'],
        ],
        'Vender' => [
            ['panel.quick-pos.index', 'Venta rápida', 'pos', 'pos.operate', 'quick_pos'],
            ['panel.pos', 'Punto de Venta', 'pos', 'pos.operate', 'pos'],
            ['panel.parts', 'Mostrador de repuestos', 'wrench', 'invoices.issue', 'billing'],
            ['panel.sales', 'Ventas', 'receipt', 'sales.view', 'sales'],
            ['panel.quotes.index', 'Cotizaciones', 'doc', 'quotes.view', 'quotes'],
            ['panel.invoices', 'Facturación', 'doc', 'invoices.view', 'billing'],
        ],
        'Inventario' => [
            ['panel.products', 'Inventario', 'cube', 'products.view', 'inventory'],
            ['panel.categories', 'Categorías', 'tag', 'categories.manage', 'inventory'],
            // Dos puertas: el MÓDULO «quick_pos» (los tamaños y sabores solo se preguntan en el
            // terminal táctil; el de mostrador ni los ofrece) y la FUNCIÓN, que enciende el propio
            // cliente. Una heladería y una ferretería pueden tener las dos el terminal, y solo a una
            // le sirve esto.
            ['panel.option-groups', 'Tamaños y sabores', 'tag', 'products.manage', 'quick_pos', 'option_groups'],
            ['panel.stock.entry', 'Entrada de mercancía', 'bag', 'stock.adjust', 'inventory'],
            ['panel.purchases', 'Compras', 'bag', 'purchases.view', 'purchasing'],
        ],
        'Clientes' => [
            ['panel.customers', 'CRM', 'users', 'customers.view', 'crm'],
            ['panel.whatsapp', 'WhatsApp', 'chat', 'whatsapp.view', 'whatsapp'],
            ['panel.social', 'Redes sociales', 'spark', 'social.view', 'social'],
            ['panel.deliveries', 'Entregas', 'truck', 'delivery.view', 'delivery'],
        ],
        'Finanzas' => [
            ['panel.finance', 'Finanzas', 'cash', 'finance.view', 'finance'],
            ['panel.expenses', 'Gastos', 'bag', 'finance.view', 'finance'],
            // Antes de Préstamos porque es lo que va antes en la vida real: primero se solicita y
            // se evalúa, y solo entonces sale el dinero.
            ['panel.loan-applications', 'Solicitudes', 'doc', 'loan_applications.view', 'loans'],
            ['panel.loans', 'Préstamos', 'loans', 'loans.view', 'loans'],
            ['panel.purchase-invoices', 'Compras (606)', 'receipt', 'purchase_invoices.view', 'billing'],
        ],
        // El patio de un dealer. Sección propia y no dentro de «Inventario» porque un vehículo no es
        // un producto: es una pieza única con su chasis y su costo, y quien lleva un dealer no busca
        // sus carros donde busca los tornillos.
        'Vehículos' => [
            ['panel.vehicles', 'Patio', 'cube', 'vehicles.view', 'dealer'],
            ['panel.vehicle-deals', 'Ventas y apartados', 'receipt', 'vehicle_deals.view', 'dealer'],
            ['panel.vehicle-jobs', 'Taller', 'wrench', 'vehicle_jobs.view', 'dealer'],
        ],
        'Equipo' => [
            ['panel.employees', 'RRHH', 'id', 'hr.view', 'hr'],
        ],
        'Inteligencia' => [
            ['panel.ai', 'IA & RAG', 'spark', 'ai.assistant.use', 'ai'],
        ],
        'Administración' => [
            ['panel.company-profile', 'Mi empresa', 'id', 'company.manage', null],
            ['panel.users', 'Usuarios', 'shield', 'users.manage', null],
            // Estaba solo en el desplegable del avatar: quien no lo abriera nunca encontraba dónde
            // ver su plan ni dónde pagar. Sin módulo asociado: la suscripción no se contrata.
            ['panel.account', 'Suscripción', 'tag', 'company.manage', null],
            ['platform.companies', 'Empresas', 'building', 'platform.manage', null],
            ['platform.plans', 'Planes', 'tag', 'platform.manage', null],
            ['platform.ai', 'IA de la plataforma', 'spark', 'platform.manage', null],
            ['platform.monitoring', 'Monitoreo', 'sliders', 'platform.manage', null],
        ],
    ];

    /*
     * Se ocultan los enlaces sin permiso, los de módulos que la empresa no contrató, los de funciones
     * que ha decidido no usar, y las secciones que quedan vacías. El super admin (Gate::before) ve
     * todo.
     *
     * Cada entrada es: [ruta, etiqueta, icono, permiso, módulo, función opcional]. El módulo y la
     * función son cosas distintas: el módulo es lo que se CONTRATÓ y lo decide el plan; la función es
     * lo que el cliente USA de lo que ya tiene, y la enciende él en «Mi empresa».
     */
    $nav = collect($nav)
        ->map(fn (array $items): array => array_values(array_filter(
            $items,
            fn (array $item): bool => Gate::allows($item[3])
                && ($isSuper || $item[4] === null || $activeCompany === null || $activeCompany->hasModule($item[4]))
                && ($isSuper || ($item[5] ?? null) === null || $activeCompany === null || $activeCompany->usesFeature($item[5])),
        )))
        ->filter(fn (array $items): bool => $items !== [])
        ->all();

    // Sección donde está la pantalla actual. Se fuerza abierta al cargar: si el usuario la había
    // plegado, dejarla cerrada le escondería dónde se encuentra.
    $seccionActiva = null;
    foreach ($nav as $seccion => $items) {
        foreach ($items as $item) {
            if (request()->routeIs($item[0])) {
                $seccionActiva = $seccion;
                break 2;
            }
        }
    }

@endphp
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'BM Business OS' }}</title>
    @if (file_exists(public_path('images/bm-mark.png')))
        <link rel="icon" type="image/png" href="{{ asset('images/bm-mark.png') }}">
    @endif
    {{-- Inter se sirve auto-alojada vía el bundle de Vite (resources/css/app.css); ya no se pide a
         Google Fonts: se elimina la latencia externa y el render no espera a un tercero. --}}
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="bmos-shell" x-data="{ open: false }" @keydown.escape.window="open = false">
    {{-- Fondo oscuro tras el cajón en móvil: al tocarlo se cierra. Sin esto, el menú abierto
         tapaba el contenido sin forma clara de cerrarlo. Solo aparece en pantallas pequeñas. --}}
    <div x-show="open" x-cloak x-transition.opacity @click="open = false"
         class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" aria-hidden="true"></div>

    {{-- Sidebar. En móvil la visibilidad la maneja el CSS (.bmos-sidebar / .is-open); en escritorio
         siempre se ve. Ya no se usan hidden/lg:flex aquí para no chocar con el CSS propio. --}}
    <aside class="bmos-sidebar" :class="{ 'is-open': open }">
        <div class="bmos-brand bmos-brand--logo-only">
            @if (file_exists(public_path('images/bm-mark.png')))
                <img src="{{ asset('images/bm-mark.png') }}?v={{ filemtime(public_path('images/bm-mark.png')) }}"
                     alt="BM Business OS" class="bmos-brand-logo-img">
            @else
                <span class="bmos-brand-logo">BM</span>
            @endif
        </div>
        {{-- Secciones plegables. Cada usuario deja abiertas las que usa y el navegador lo recuerda:
             sin persistir, cada clic recargaría la página y volvería a abrirlas todas, que es peor
             que no poder plegarlas. --}}
        <nav class="bmos-nav" x-data="menuLateral(@js($seccionActiva))">
            @foreach ($nav as $section => $items)
                <button type="button" @click="alternar(@js($section))"
                        :aria-expanded="abierta(@js($section)) ? 'true' : 'false'"
                        class="bmos-nav-section">
                    <span>{{ $section }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                         class="bmos-nav-chevron" :class="abierta(@js($section)) && 'is-open'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                {{-- Sin `x-collapse`: ese plugin de Alpine no está instalado y usarlo no haría nada.
                     Un x-show simple basta y no añade una dependencia por una animación. --}}
                <div x-show="abierta(@js($section))" x-cloak>
                    @foreach ($items as [$route, $label, $icon, $permission, $module])
                        <a href="{{ route($route) }}"
                           class="bmos-nav-link {{ request()->routeIs($route) ? 'is-active' : '' }}">
                            <x-icono :name="$icon" stroke-width="1.6" />
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>
    </aside>

    {{-- Main. «min-w-0» es imprescindible: sin él, una tabla ancha dentro de un overflow-x-auto
         no deja encoger esta columna y estira toda la página (en móvil se ve diminuta). --}}
    <div class="flex min-h-screen min-w-0 flex-col">
        <header class="bmos-topbar">
            <div class="flex items-center gap-3">
                <button class="lg:hidden text-slate-500" @click="open = !open" aria-label="Menú">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-6 h-6">
                        <path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                    </svg>
                </button>
                @if ($isSuper)
                    <div x-data="{ open: false }" class="relative">
                        <button type="button" @click="open = !open" class="bmos-company-chip">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                            </svg>
                            {{ $activeCompany?->name ?? 'Plataforma' }}
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-transition x-cloak
                             class="absolute left-0 z-30 mt-2 max-h-80 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                            <div class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Cambiar empresa</div>
                            @foreach ($companies as $c)
                                <form method="POST" action="{{ route('panel.company.switch', $c) }}">
                                    @csrf
                                    <button type="submit"
                                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50 {{ $activeCompany?->id === $c->id ? 'font-semibold text-indigo-600' : 'text-slate-700' }}">
                                        <span class="truncate">{{ $c->name }}</span>
                                        @if ($activeCompany?->id === $c->id)
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="h-4 w-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @else
                    <span class="bmos-company-chip">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
                        </svg>
                        {{ $activeCompany?->name ?? 'Plataforma' }}
                    </span>
                @endif
            </div>

            <div class="flex items-center gap-3">
                {{-- La ayuda se pide en el momento en que uno se atasca, así que tiene que estar en
                     todas las pantallas y no escondida en un menú. --}}
                <a href="{{ route('panel.help') }}" title="Ayuda"
                   class="flex h-9 w-9 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-indigo-600">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z"/>
                    </svg>
                </a>

                <x-panel.alerts-bell />

                <div class="relative" x-data="{ menu: false }">
                    <button class="flex items-center gap-2" @click="menu = !menu">
                        <span class="bmos-avatar">{{ $initial }}</span>
                        <span class="hidden sm:block text-sm font-semibold text-slate-700">{{ $authUser?->name }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4 text-slate-400">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                        </svg>
                    </button>
                    <div x-show="menu" @click.outside="menu = false" x-transition x-cloak
                         class="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                        <div class="px-4 py-2 text-xs text-slate-400">{{ $authUser?->email }}</div>
                        @can('company.manage')
                            <a href="{{ route('panel.account') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Mi suscripción</a>
                        @endcan
                        {{-- «Mi portal» solo a quien tiene ficha de empleado. Se ofrecía a todo el
                             mundo, y para el resto —que son la mayoría: el dueño no suele estar en la
                             plantilla— llevaba a una pantalla que solo decía «no estás vinculado». Un
                             enlace que no lleva a ningún sitio es peor que no tenerlo. --}}
                        @if ($authUser?->esEmpleado())
                            <a href="{{ route('portal.employee') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Mi portal</a>
                            @can('delivery.own')
                                <a href="{{ route('portal.deliveries') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Mis entregas</a>
                            @endcan
                        @endif
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">Cerrar sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        {{-- `wide`: pantallas que aprovechan todo el ancho, como el punto de venta táctil. El resto
             del panel conserva el ancho máximo de lectura, que es lo cómodo para tablas y formularios. --}}
        <main class="bmos-content {{ ($wide ?? false) ? 'bmos-content--wide' : '' }}">
            <div class="mb-6">
                <h1 class="bmos-page-title">{{ $heading ?? ($title ?? 'Dashboard') }}</h1>
                @isset($subheading)
                    <p class="bmos-page-sub">{{ $subheading }}</p>
                @endisset
            </div>

            {{-- Aviso flotante «Registro exitoso» / error. Global: cubre cualquier acción del panel. --}}
            @include('partials.toast')

            {{-- Aviso de vencimiento de suscripción/prueba (banner + ventana emergente). --}}
            @include('partials.subscription-notice')
    @include('partials.ios-install-hint')
    @include('partials.pwa-install-banner')

    {{-- El asistente de ayuda, flotando sobre cualquier pantalla.

         Sólo si el operador de la plataforma lo encendió para esta empresa: cada pregunta se paga en
         el proveedor de IA y la paga él. Que no se pinte NO es la seguridad —el endpoint lo vuelve a
         comprobar—, es sólo no enseñar un botón que no va a funcionar. --}}
    @if ($activeCompany?->usaAsistente())
        @include('partials.asistente')
    @endif

            {{ $slot }}
        </main>
    </div>
</div>
<style>[x-cloak]{display:none!important}</style>
</body>
</html>
