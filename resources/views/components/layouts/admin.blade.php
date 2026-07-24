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
    $nav = [
        'Principal' => [
            ['dashboard', 'Dashboard', 'home', 'dashboard.view', null],
            ['panel.reports', 'Reportes', 'chart', 'reports.view', 'reports'],
        ],
        'Operación' => [
            ['panel.pos', 'Punto de Venta', 'pos', 'pos.operate', 'pos'],
            ['panel.sales', 'Ventas', 'receipt', 'sales.view', 'sales'],
            ['panel.purchases', 'Compras', 'bag', 'purchases.view', 'purchasing'],
            ['panel.products', 'Inventario', 'cube', 'products.view', 'inventory'],
            ['panel.stock.entry', 'Entrada de mercancía', 'bag', 'stock.adjust', 'inventory'],
        ],
        'Clientes' => [
            ['panel.customers', 'CRM', 'users', 'customers.view', 'crm'],
            ['panel.whatsapp', 'WhatsApp', 'chat', 'whatsapp.view', 'whatsapp'],
        ],
        'Finanzas' => [
            ['panel.parts', 'Mostrador de repuestos', 'wrench', 'invoices.issue', 'billing'],
            ['panel.invoices', 'Facturación', 'doc', 'invoices.view', 'billing'],
            ['panel.finance', 'Finanzas', 'cash', 'finance.view', 'finance'],
            ['panel.loans', 'Préstamos', 'loans', 'loans.view', 'loans'],
        ],
        'Logística y equipo' => [
            ['panel.deliveries', 'Entregas', 'truck', 'delivery.view', 'delivery'],
            ['panel.employees', 'RRHH', 'id', 'hr.view', 'hr'],
        ],
        'Inteligencia' => [
            ['panel.ai', 'IA & RAG', 'spark', 'ai.assistant.use', 'ai'],
        ],
        'Administración' => [
            ['panel.users', 'Usuarios', 'shield', 'users.manage', null],
            ['platform.companies', 'Empresas', 'building', 'platform.manage', null],
            ['platform.plans', 'Planes', 'tag', 'platform.manage', null],
        ],
    ];

    // Se ocultan los enlaces sin permiso, los de módulos que la empresa no contrató, y las
    // secciones que quedan vacías. El super admin (Gate::before) ve todo.
    $nav = collect($nav)
        ->map(fn (array $items): array => array_values(array_filter(
            $items,
            fn (array $item): bool => Gate::allows($item[3])
                && ($isSuper || $item[4] === null || $activeCompany === null || $activeCompany->hasModule($item[4])),
        )))
        ->filter(fn (array $items): bool => $items !== [])
        ->all();

    $icons = [
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/>',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>',
        'pos' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h7.5M4.5 4.5h15a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75h-15a.75.75 0 0 1-.75-.75V5.25a.75.75 0 0 1 .75-.75Z"/>',
        'receipt' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185Z"/>',
        'bag' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/>',
        'cube' => '<path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/>',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>',
        'doc' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
        'cash' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'truck' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.66-.831H14.25M16.5 18.75h-6m6 0v-6.75m0 6.75-1.5-6.75m0 0V6.75A2.25 2.25 0 0 0 12.75 4.5H4.5a2.25 2.25 0 0 0-2.25 2.25v10.5"/>',
        'loans' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797-2.101c.727-.198 1.453.164 1.453.925V19.5a2.25 2.25 0 0 1-2.25 2.25H2.25V18.75Zm0 0a2.25 2.25 0 0 0 2.25 2.25h.75m-3-2.25V6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5a2.25 2.25 0 0 1-2.25 2.25h-.75m-9-6a3.75 3.75 0 1 1 7.5 0 3.75 3.75 0 0 1-7.5 0Z"/>',
        'id' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
        'spark' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/>',
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/>',
        'building' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>',
        'tag' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/>',
        'wrench' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26"/>',
        'sliders' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/>',
    ];
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        <nav class="bmos-nav">
            @foreach ($nav as $section => $items)
                <p class="bmos-nav-section">{{ $section }}</p>
                @foreach ($items as [$route, $label, $icon, $permission, $module])
                    <a href="{{ route($route) }}"
                       class="bmos-nav-link {{ request()->routeIs($route) ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                            {!! $icons[$icon] !!}
                        </svg>
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
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
                        <a href="{{ route('portal.employee') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">Mi portal</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">Cerrar sesión</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="bmos-content">
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

            {{ $slot }}
        </main>
    </div>
</div>
<style>[x-cloak]{display:none!important}</style>
</body>
</html>
