<x-layouts.admin title="Mostrador de repuestos" heading="Mostrador de repuestos" subheading="Busca la pieza, arma el ticket y factura descontando stock">

    {{-- Acuse con enlace al recibo tras facturar (el aviso de éxito/error lo pinta el toast global). --}}
    @if (session('pos_receipt_id'))
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            <span class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                {{ session('panel_ok') ?? 'Factura emitida.' }}
            </span>
            <div class="flex gap-2">
                <a href="{{ route('panel.sales.receipt', session('pos_receipt_id')) }}?print=1" target="_blank" rel="noopener"
                   class="bmos-btn bmos-btn-primary text-xs">🖨️ Imprimir recibo</a>
                <a href="{{ route('panel.sales.receipt.pdf', ['sale' => session('pos_receipt_id'), 'mode' => 'descargar']) }}"
                   class="bmos-btn bmos-btn-ghost text-xs">⬇️ PDF 80mm</a>
            </div>
        </div>
    @endif

    @unless ($hasWarehouse)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            No hay un almacén por defecto configurado. Créalo antes de facturar desde el mostrador.
        </div>
    @endunless

    <div x-data="partsCounter('{{ route('panel.parts.search') }}')" class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        {{-- Buscador + resultados --}}
        <div class="lg:col-span-2">
            <div class="bmos-card bmos-card-pad mb-4">
                <label class="bmos-field-label" for="parts-search">Buscar pieza</label>
                <input id="parts-search" type="text" x-ref="searchInput" x-model="query" @input.debounce.300ms="search()"
                       autofocus autocomplete="off"
                       placeholder="Nombre, nº de parte, marca o vehículo (ej. «corolla», «filtro», «90915»)"
                       class="bmos-input">
                <p x-show="searchError" x-cloak x-text="searchError" class="mt-2 text-sm text-amber-700"></p>
            </div>

            <div class="space-y-2">
                <template x-for="p in results" :key="p.id">
                    <button type="button" @click="add(p)" :disabled="!p.sellable"
                            class="flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50/40"
                            :class="!p.sellable ? 'opacity-50 cursor-not-allowed' : ''">
                        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-lg bg-slate-50 text-xl">🔧</div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold text-slate-800" x-text="p.name"></p>
                            <p class="truncate text-xs text-slate-400">
                                <span x-show="p.part_number" x-text="p.part_number"></span>
                                <span x-show="p.brand" x-text="(p.part_number ? ' · ' : '') + p.brand"></span>
                                <span x-show="p.vehicle" x-text="' · ' + p.vehicle"></span>
                                <span x-show="p.location" x-text="' · 📍 ' + p.location"></span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-indigo-600" x-text="parseFloat(p.price).toFixed(2)"></p>
                            <span class="text-xs" :class="parseFloat(p.stock) < 5 ? 'text-amber-600' : 'text-slate-400'" x-text="Math.round(p.stock) + ' u.'"></span>
                        </div>
                    </button>
                </template>
                <p x-show="!results.length && query.trim() && !busy" x-cloak class="bmos-empty">Sin resultados para «<span x-text="query"></span>».</p>
                <p x-show="!query.trim()" class="py-8 text-center text-sm text-slate-400">Escribe para buscar una pieza.</p>
            </div>
        </div>

        {{-- Ticket + facturación --}}
        <div>
            <form method="POST" action="{{ route('panel.parts.invoice') }}" x-ref="form"
                  @submit="$refs.cartInput.value = JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty })))"
                  class="bmos-card bmos-card-pad">
                @csrf
                <input type="hidden" name="cart" x-ref="cartInput">

                <p class="mb-3 font-semibold text-slate-800">Ticket</p>

                <div class="max-h-56 space-y-2 overflow-y-auto">
                    <template x-for="(item, i) in cart" :key="item.id">
                        <div class="flex items-center gap-2 rounded-lg bg-slate-50 p-2">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-700" x-text="item.name"></p>
                                <p class="text-xs text-slate-400"><span x-text="item.price.toFixed(2)"></span> c/u</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="dec(i)" class="h-6 w-6 rounded bg-white text-slate-600 shadow-sm">−</button>
                                <span class="w-6 text-center text-sm font-semibold" x-text="item.qty"></span>
                                <button type="button" @click="inc(i)" class="h-6 w-6 rounded bg-white text-slate-600 shadow-sm">+</button>
                            </div>
                            <span class="w-16 text-right text-sm font-semibold" x-text="(item.price*item.qty).toFixed(2)"></span>
                        </div>
                    </template>
                    <p x-show="cart.length === 0" class="py-6 text-center text-sm text-slate-400">Busca y agrega una pieza.</p>
                </div>

                <div class="mt-3 border-t border-slate-100 pt-3">
                    <div class="flex items-center justify-between text-lg font-bold text-slate-800">
                        <span>Total</span><span x-text="total.toFixed(2)"></span>
                    </div>

                    <label class="bmos-field-label mt-3">Tipo de comprobante (NCF)</label>
                    <select name="type" x-model="ncfType" class="bmos-input">
                        @foreach ($ncfTypes as $type)
                            <option value="{{ $type->value }}" data-requires="{{ $type->requiresTaxId() ? '1' : '0' }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>

                    <label class="bmos-field-label mt-3">Cliente (opcional)</label>
                    <select name="customer_id" x-model="customerId" class="bmos-input">
                        <option value="">Sin identificar</option>
                        @foreach ($customers as $customerOption)
                            <option value="{{ $customerOption->id }}" data-tax="{{ $customerOption->tax_id }}">{{ $customerOption->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="customer_name" x-model="customer" x-show="!customerId"
                           placeholder="Consumidor final" class="bmos-input mt-2">

                    <label class="bmos-field-label mt-3">RNC / Cédula <span x-show="requiresTaxId" class="text-rose-500">*</span></label>
                    <input type="text" name="customer_tax_id" x-model="taxId"
                           placeholder="Obligatorio para Crédito Fiscal / Gubernamental" class="bmos-input">

                    <label class="bmos-field-label mt-3">Pago recibido</label>
                    <input type="number" name="paid" step="0.01" min="0" x-model="paid" placeholder="0.00" class="bmos-input">
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-slate-500">Cambio</span>
                        <span class="font-semibold text-emerald-600" x-text="change.toFixed(2)"></span>
                    </div>

                    <button type="submit" :disabled="!canInvoice"
                            class="bmos-btn bmos-btn-primary mt-4 w-full justify-center"
                            :class="!canInvoice ? 'opacity-50 cursor-not-allowed' : ''">
                        Facturar <span x-show="cart.length" x-text="'· ' + total.toFixed(2)"></span>
                    </button>
                    <p x-show="requiresTaxId && !taxId.trim()" x-cloak class="mt-2 text-center text-xs text-amber-600">
                        Este tipo de comprobante exige RNC/Cédula del cliente.
                    </p>
                </div>
            </form>
        </div>
    </div>

    <script>
        function partsCounter(searchUrl) {
            return {
                query: '', results: [], busy: false, searchError: '',
                cart: [], paid: '', customer: '', customerId: '', taxId: '', ncfType: 'B02',

                async search() {
                    const q = this.query.trim();
                    if (!q) { this.results = []; return; }
                    this.busy = true; this.searchError = '';
                    try {
                        const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                        if (!res.ok) { this.searchError = 'No se pudo buscar. Recarga la página.'; return; }
                        const data = await res.json();
                        this.results = data.results || [];
                    } catch {
                        this.searchError = 'Sin conexión con el servidor. Inténtalo de nuevo.';
                    } finally {
                        this.busy = false;
                    }
                },

                add(p) {
                    if (!p.sellable) return;
                    const it = this.cart.find(i => i.id === p.id);
                    if (it) it.qty++;
                    else this.cart.push({ id: p.id, name: p.name, price: parseFloat(p.price), qty: 1 });
                },
                inc(i) { this.cart[i].qty++; },
                dec(i) { if (this.cart[i].qty > 1) this.cart[i].qty--; else this.cart.splice(i, 1); },
                get total() { return this.cart.reduce((s, i) => s + i.price * i.qty, 0); },
                get change() { const p = parseFloat(this.paid || 0); return Math.max(0, p - this.total); },
                get requiresTaxId() {
                    const opt = this.$el?.querySelector(`select[name=type] option[value="${this.ncfType}"]`);
                    return opt?.dataset.requires === '1';
                },
                get canInvoice() {
                    const paidOk = parseFloat(this.paid || 0) >= this.total && this.total > 0;
                    const taxOk = !this.requiresTaxId || this.taxId.trim().length > 0;
                    return this.cart.length > 0 && paidOk && taxOk;
                },
            };
        }
    </script>
</x-layouts.admin>
