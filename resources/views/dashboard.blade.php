@php
    $modules = [
        ['dashboard', 'Punto de Venta', 'panel.pos', 'Vender rápido y cobrar', 'tone-indigo', 'M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h7.5M4.5 4.5h15a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75h-15a.75.75 0 0 1-.75-.75V5.25a.75.75 0 0 1 .75-.75Z'],
        ['x', 'Inventario', 'panel.products', 'Productos y existencias', 'tone-sky', 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9'],
        ['x', 'Ventas', 'panel.sales', 'Historial de ventas', 'tone-emerald', 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185Z'],
        ['x', 'Compras', 'panel.purchases', 'Órdenes y proveedores', 'tone-amber', 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z'],
        ['x', 'CRM', 'panel.customers', 'Clientes y oportunidades', 'tone-violet', 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        ['x', 'WhatsApp', 'panel.whatsapp', 'Conversaciones', 'tone-emerald', 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242'],
        ['x', 'Facturación', 'panel.invoices', 'Facturas con NCF', 'tone-sky', 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
        ['x', 'Mostrador de repuestos', 'panel.parts', 'Facturar piezas con stock', 'tone-indigo', 'M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z'],
        ['x', 'Finanzas', 'panel.finance', 'Cuentas y movimientos', 'tone-emerald', 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z'],
        ['x', 'Entregas', 'panel.deliveries', 'Reparto y estados', 'tone-amber', 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.66-.831H14.25'],
        ['x', 'RRHH', 'panel.employees', 'Empleados y asistencia', 'tone-rose', 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z'],
        ['x', 'IA & RAG', 'panel.ai', 'Base de conocimiento', 'tone-violet', 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z'],
        ['x', 'Reportes', 'panel.reports', 'Resumen ejecutivo', 'tone-indigo', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
    ];

    // Las tarjetas de acceso rápido obedecen al mismo permiso que protege cada ruta y al módulo
    // que la empresa debe tener contratado: si el usuario no puede abrir el módulo (o su plan no
    // lo incluye), tampoco se le ofrece la puerta.
    $modulePermission = [
        'panel.pos' => ['pos.operate', 'pos'],
        'panel.products' => ['products.view', 'inventory'],
        'panel.sales' => ['sales.view', 'sales'],
        'panel.purchases' => ['purchases.view', 'purchasing'],
        'panel.customers' => ['customers.view', 'crm'],
        'panel.whatsapp' => ['whatsapp.view', 'whatsapp'],
        'panel.invoices' => ['invoices.view', 'billing'],
        'panel.parts' => ['invoices.issue', 'billing'],
        'panel.finance' => ['finance.view', 'finance'],
        'panel.deliveries' => ['delivery.view', 'delivery'],
        'panel.employees' => ['hr.view', 'hr'],
        'panel.ai' => ['ai.assistant.use', 'ai'],
        'panel.reports' => ['reports.view', 'reports'],
    ];

    $isSuper = (bool) auth()->user()?->is_super_admin;

    $modules = array_values(array_filter($modules, function (array $module) use ($modulePermission, $company, $isSuper): bool {
        [$permission, $moduleKey] = $modulePermission[$module[2]];

        return Illuminate\Support\Facades\Gate::allows($permission)
            && ($isSuper || $company === null || $company->hasModule($moduleKey));
    }));

    $stats = [
        ['Ventas (total)', money($summary['sales_total']), $summary['sales_count'].' ventas', 'tone-emerald', 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185Z'],
        ['Balance de caja', money($summary['cash_balance']), 'Efectivo disponible', 'tone-indigo', 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z'],
        ['Oportunidades', (string) $summary['open_opportunities'], 'Abiertas en el CRM', 'tone-violet', 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
        ['Entregas pendientes', (string) $summary['pending_deliveries'], 'En logística', 'tone-amber', 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.66-.831H14.25'],
    ];

    // Iniciales para el avatar del usuario (máx. 2 letras).
    $initials = collect(explode(' ', trim((string) $user->name)))
        ->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $initials = $initials !== '' ? $initials : 'U';

    $lowStock = (int) $summary['low_stock'];
@endphp

<x-layouts.admin title="Dashboard" heading="Dashboard" :subheading="'Resumen de ' . ($company?->name ?? 'la plataforma')">
    {{-- KPIs --}}
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as [$label, $value, $hint, $tone, $path])
            <div class="group rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-900/5 transition duration-150 hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-200/60">
                <div class="flex items-start justify-between gap-3">
                    <p class="bmos-stat-label">{{ $label }}</p>
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl {{ $tone }} transition group-hover:scale-105">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
                        </svg>
                    </span>
                </div>
                <p class="mt-2 text-3xl font-bold tracking-tight text-slate-800" @if($label === 'Balance de caja') data-testid="kpi-cash-balance" @endif>{{ $value }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    {{-- Módulos --}}
    <h2 class="mt-9 mb-3 text-lg font-semibold text-slate-800">Módulos del sistema</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($modules as [$_, $label, $route, $desc, $tone, $path])
            <a href="{{ route($route) }}" class="bmos-module group">
                <span class="bmos-module-icon {{ $tone }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $path }}"/>
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-slate-800">{{ $label }}</span>
                    <span class="block truncate text-xs text-slate-500">{{ $desc }}</span>
                </span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-indigo-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
                </svg>
            </a>
        @endforeach
    </div>

    {{-- Contexto de sesión --}}
    <h2 class="mt-9 mb-3 text-lg font-semibold text-slate-800">Resumen ejecutivo</h2>
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Usuario --}}
        <div class="bmos-card bmos-card-pad">
            <div class="flex items-center gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-base font-bold text-white shadow-sm">{{ $initials }}</span>
                <div class="min-w-0">
                    <p class="truncate text-lg font-semibold text-slate-800" data-testid="user-name">{{ $user->name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ $user->email }}</p>
                </div>
            </div>
            <p class="mt-4 bmos-stat-label">Roles</p>
            <div class="mt-1.5 flex flex-wrap gap-1.5" data-testid="user-roles">
                @forelse ($roles as $role)
                    <span class="bmos-badge badge-violet">{{ $role }}</span>
                @empty
                    <span class="text-sm text-slate-400">Sin roles asignados</span>
                @endforelse
            </div>
        </div>

        {{-- Empresa activa --}}
        <div class="bmos-card bmos-card-pad">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl tone-indigo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="bmos-stat-label">Empresa activa</p>
                    <p class="truncate text-lg font-semibold text-slate-800" data-testid="company-name">{{ $company?->name ?? '—' }}</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-slate-50 p-3 text-center ring-1 ring-slate-100">
                    <p class="text-2xl font-bold text-indigo-600">{{ $branchesCount }}</p>
                    <p class="text-xs text-slate-400">Sucursales</p>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 text-center ring-1 ring-slate-100">
                    <p class="text-2xl font-bold text-indigo-600">{{ $warehousesCount }}</p>
                    <p class="text-xs text-slate-400">Almacenes</p>
                </div>
            </div>
        </div>

        {{-- Inventario --}}
        <div class="bmos-card bmos-card-pad">
            <div class="flex items-center gap-3">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl tone-sky">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="bmos-stat-label">Inventario</p>
                    <p class="truncate text-lg font-semibold text-slate-800">Existencias</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-slate-50 p-3 text-center ring-1 ring-slate-100">
                    <p class="text-2xl font-bold text-slate-800">{{ $summary['products'] }}</p>
                    <p class="text-xs text-slate-400">Productos</p>
                </div>
                @if ($lowStock > 0)
                    <a href="{{ route('panel.products', ['filter' => 'low_stock']) }}"
                       class="block rounded-xl p-3 text-center ring-1 bg-amber-50 ring-amber-100 transition hover:bg-amber-100 hover:ring-amber-200"
                       title="Ver los productos con stock bajo">
                        <p class="text-2xl font-bold text-amber-600">{{ $lowStock }}</p>
                        <p class="text-xs text-amber-700/70">Stock bajo →</p>
                    </a>
                @else
                    <div class="rounded-xl p-3 text-center ring-1 bg-slate-50 ring-slate-100">
                        <p class="text-2xl font-bold text-slate-800">0</p>
                        <p class="text-xs text-slate-400">Stock bajo</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.admin>
