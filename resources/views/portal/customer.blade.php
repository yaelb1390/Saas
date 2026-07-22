{{--
    Portal del cliente. Se abre casi siempre desde el móvil, a través del enlace que llega por
    WhatsApp, así que la lectura en pantalla pequeña manda: una columna, tarjetas y sin tablas
    anchas que obliguen a hacer scroll lateral.

    Es de solo lectura y no lleva navegación al panel: quien está aquí no es un usuario del sistema.
--}}
<x-layouts.app title="Mi cuenta · {{ $company->name }}">
    <x-slot:header>
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $company->name }}</h1>
            <p class="mt-1 text-sm text-gray-500">Hola, {{ $customer->name }}</p>
        </div>
    </x-slot:header>

    {{-- Ficha del cliente: sus propios datos de contacto, tal como los tiene el negocio. --}}
    <div class="rounded-xl bg-white p-6 shadow" data-testid="customer-card">
        <h2 class="font-semibold text-gray-900">Mis datos</h2>
        <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">Nombre</dt>
                <dd class="font-medium text-gray-900">{{ $customer->name }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Teléfono</dt>
                <dd class="font-medium text-gray-900">{{ $customer->phone ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Correo</dt>
                <dd class="font-medium text-gray-900">{{ $customer->email ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">RNC / Cédula</dt>
                <dd class="font-medium text-gray-900">{{ $customer->tax_id ?: '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-gray-500">Dirección</dt>
                <dd class="font-medium text-gray-900">{{ $customer->address ?: '—' }}</dd>
            </div>
        </dl>
        <p class="mt-4 text-xs text-gray-400">
            ¿Algo no está correcto? Escríbenos y lo actualizamos.
        </p>
    </div>

    {{-- Cada bloque solo aparece si la empresa tiene contratado el módulo correspondiente. --}}
    @if ($invoices->isNotEmpty())
        <div class="mt-6 rounded-xl bg-white p-6 shadow" data-testid="invoices-card">
            <h2 class="mb-3 font-semibold text-gray-900">Mis facturas</h2>
            @foreach ($invoices as $invoice)
                <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-3 last:border-0">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-gray-900">{{ $invoice->ncf }}</p>
                        <p class="text-xs text-gray-500">
                            {{ $invoice->issued_at?->format('d/m/Y') ?? '—' }} · {{ $invoice->status->label() }}
                        </p>
                    </div>
                    <span class="shrink-0 font-semibold text-gray-900">
                        {{ number_format((float) $invoice->total, 2) }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($sales->isNotEmpty())
        <div class="mt-6 rounded-xl bg-white p-6 shadow" data-testid="sales-card">
            <h2 class="mb-3 font-semibold text-gray-900">Mis compras</h2>
            @foreach ($sales as $sale)
                <div class="border-b border-gray-100 py-3 last:border-0">
                    <div class="flex items-baseline justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-gray-900">{{ $sale->code }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $sale->completed_at?->format('d/m/Y H:i') ?? '—' }}
                                · {{ trans_choice(':count artículo|:count artículos', $sale->items->count()) }}
                            </p>
                        </div>
                        <span class="shrink-0 font-semibold text-gray-900">
                            {{ number_format((float) $sale->total, 2) }}
                        </span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($deliveries->isNotEmpty())
        <div class="mt-6 rounded-xl bg-white p-6 shadow" data-testid="deliveries-card">
            <h2 class="mb-3 font-semibold text-gray-900">Mis entregas</h2>
            @foreach ($deliveries as $delivery)
                <div class="flex items-baseline justify-between gap-3 border-b border-gray-100 py-3 last:border-0">
                    <div class="min-w-0">
                        <p class="truncate font-medium text-gray-900">{{ $delivery->code }}</p>
                        <p class="truncate text-xs text-gray-500">{{ $delivery->address }}</p>
                    </div>
                    <span class="shrink-0 text-sm font-medium text-gray-600">
                        {{ $delivery->status->label() }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($invoices->isEmpty() && $sales->isEmpty() && $deliveries->isEmpty())
        <div class="mt-6 rounded-xl bg-white p-6 shadow" data-testid="portal-empty">
            <p class="text-sm text-gray-500">Todavía no hay documentos para mostrar en tu cuenta.</p>
        </div>
    @endif

    <p class="mt-6 text-center text-xs text-gray-400">
        Este enlace es personal y caduca. No lo compartas.
    </p>
</x-layouts.app>
