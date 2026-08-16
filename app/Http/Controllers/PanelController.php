<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiDocument;
use App\Modules\AI\Models\AiSentimentAnalysis;
use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\PolarCheckoutService;
use App\Modules\Core\Support\RoleCatalog;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use App\Modules\Loans\Enums\InstallmentStatus;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Models\Loan;
use App\Modules\POS\Support\PosProfile;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use App\Modules\WhatsApp\Support\InboxPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Capa de presentación del panel de administración. Compone datos (solo lectura) de los distintos
 * módulos para las pantallas del back-office. Toda consulta ya viene aislada por la empresa activa.
 */
final class PanelController extends Controller
{
    public function pos(CurrentCompany $current): View
    {
        $company = $current->model();

        return view('panel.pos', [
            // El catálogo YA NO se carga entero: el terminal busca productos bajo demanda
            // (panel.pos.search). Así el POS escala a miles de productos sin traerlos todos.
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'openSession' => CashSession::query()->where('status', 'open')->latest('opened_at')->first(),
            'posConfig' => $company !== null ? PosProfile::for($company) : ['profile' => PosProfile::DEFAULT, 'options' => PosProfile::defaults(PosProfile::DEFAULT)],
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function products(CurrentCompany $current): View
    {
        $company = $current->model();

        return view('panel.products', [
            'products' => Product::query()->with(['category', 'stock'])
                // Mismo filtro que usa el borrado múltiple (ver Product::scopeFiltered): así
                // «seleccionar todos los que coinciden» borra exactamente lo que hay en pantalla.
                ->filtered(request('q'), request('filter') === 'low_stock')
                ->orderBy('name')->paginate(15)->withQueryString(),
            'lowStockFilter' => request('filter') === 'low_stock',
            'categories' => Category::query()->orderBy('name')->get(),
            // Los datos de pieza de vehículo solo tienen sentido en un negocio de repuestos.
            'showPartFields' => $company !== null && PosProfile::for($company)['profile'] === 'repuestos',
        ]);
    }

    public function categories(): View
    {
        return view('panel.categories', [
            // withCount evita el N+1 al pintar cuántos productos tiene cada fila, y `parent` se
            // precarga porque la tabla muestra el nombre del padre.
            'categories' => Category::query()->with('parent')->withCount('products')
                ->orderBy('name')->get(),
        ]);
    }

    public function optionGroups(): View
    {
        return view('panel.option-groups', [
            // `options` y `products` se precargan porque cada tarjeta las pinta: sin esto, una
            // pantalla con 10 grupos lanzaría 20 consultas extra.
            'groups' => OptionGroup::query()->with(['options', 'products:id,name'])
                ->orderBy('sort_order')->orderBy('name')->get(),

            // Para el selector de productos. Solo id y nombre: la lista puede ser larga y no hace
            // falta traer precios, fotos ni existencias para pintar unas casillas.
            'products' => Product::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function sales(): View
    {
        return view('panel.sales', [
            'sales' => Sale::query()->withCount('items')
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('code', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")
                ))
                ->latest()->paginate(15)->withQueryString(),
        ]);
    }

    public function purchases(): View
    {
        return view('panel.purchases', [
            'orders' => PurchaseOrder::query()->with('supplier')->withCount('items')->latest()->paginate(15),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            // Necesarios para armar las líneas de una orden nueva.
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'cost']),
            'warehouses' => Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get(),
        ]);
    }

    /**
     * Entrada de mercancía: se arma la remesa escaneando y se confirma entera de una vez.
     *
     * El panel lateral enseña las ÚLTIMAS REMESAS, no los últimos movimientos de existencia. Antes
     * mostraba los movimientos y ahí salían también las ventas del punto de venta: en una pantalla de
     * entradas, ver «−1 Venta» entre lo que acabas de meter no es un acuse de recibo, es ruido. Y los
     * productos borrados aparecían como un guion, así que había filas que no decían nada.
     */
    public function stockEntry(): View
    {
        return view('panel.stock-entry', [
            'warehouses' => Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'receipts' => GoodsReceipt::query()
                ->with(['lines', 'warehouse', 'supplier'])
                ->withCount('lines')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    public function customers(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->model();

        return view('panel.customers', [
            'customers' => Customer::query()->withCount('opportunities')
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('name', "%{$q}%")->orWhereLike('phone', "%{$q}%")->orWhereLike('email', "%{$q}%")
                ))
                ->orderBy('name')->paginate(15)->withQueryString(),
            'opportunities' => Opportunity::query()->with(['customer', 'stage'])->latest()->take(10)->get(),
            // El enlace del portal se entrega por WhatsApp: sin ese módulo, no se ofrece el botón.
            'portalEnabled' => $company?->hasModule('whatsapp') ?? false,
        ]);
    }

    public function whatsapp(WhatsAppConnection $connection, InboxPresenter $inbox): View
    {
        // Si Evolution está caído, la bandeja debe seguir siendo usable.
        try {
            $status = $connection->status();
        } catch (Throwable) {
            $status = ['state' => 'error', 'instance' => '—', 'connected' => false];
        }

        // Misma forma que devuelve el endpoint de sondeo: la vista se pinta una sola vez
        // y luego se refresca sola con esos mismos datos.
        return view('panel.whatsapp', [
            'inbox' => $inbox->payload((string) request('c', '')),
            'status' => $status,
        ]);
    }

    public function invoices(): View
    {
        return view('panel.invoices', [
            'invoices' => Invoice::query()
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('ncf', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")
                ))
                ->latest()->paginate(15)->withQueryString(),

            'sequences' => FiscalSequence::query()->orderBy('type')->get(),

            // Ventas completadas que aún no tienen comprobante: son las facturables.
            // Se resuelve con una subconsulta y no con una relación Sale->invoice, porque Sales
            // no debe conocer a Billing (la dependencia es en un solo sentido).
            'invoiceableSales' => Sale::query()
                ->where('status', SaleStatus::Completed)
                ->whereNotIn('id', Invoice::query()->select('sale_id')->whereNotNull('sale_id'))
                ->latest()
                ->limit(50)
                ->get(),

            'ncfTypes' => NcfType::cases(),
            'cancellationReasons' => CancellationReason::cases(),
            'period' => (string) request('period', now()->format('Y-m')),
        ]);
    }

    public function finance(): View
    {
        return view('panel.finance', [
            'accounts' => Account::query()->orderBy('name')->get(),
            'movements' => FinancialMovement::query()->with('account')->latest('occurred_at')->paginate(15),
        ]);
    }

    /**
     * Cartera de préstamos. `installments_min_due_date` es el próximo vencimiento sin pagar (subquery,
     * sin cargar todas las cuotas). El filtro «overdue» deja solo los que tienen cuotas vencidas.
     */
    public function loans(ReportService $reports): View
    {
        $loans = Loan::query()
            ->with('customer')
            ->withMin(['installments' => fn ($q) => $q->where('status', '!=', InstallmentStatus::Paid->value)], 'due_date')
            ->when(request('q'), function ($query, $q) {
                // Se busca por código, nombre y cédula del cliente. La cédula se compara también sin
                // guiones/espacios (REPLACE es portable PG/SQLite) para encontrarla se escriba
                // «001-1909443-4» o «0011909434».
                $digits = preg_replace('/\D/', '', (string) $q);

                $query->where(function ($sub) use ($q, $digits) {
                    $sub->whereLike('code', "%{$q}%")
                        ->orWhereLike('customer_name', "%{$q}%")
                        ->orWhereHas('customer', function ($c) use ($q, $digits) {
                            $c->whereLike('cedula', "%{$q}%");
                            if ($digits !== '' && $digits !== null) {
                                $c->orWhereRaw("REPLACE(REPLACE(cedula, '-', ''), ' ', '') LIKE ?", ["%{$digits}%"]);
                            }
                        });
                });
            })
            ->when(request('filter') === 'overdue', fn ($query) => $query->whereHas('installments', fn ($i) => $i
                ->where('status', '!=', InstallmentStatus::Paid->value)
                ->whereDate('due_date', '<', now()->toDateString())))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('panel.loans', [
            'loans' => $loans,
            'customers' => Customer::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'frequencies' => LoanFrequency::cases(),
            // Resumen de la cartera (misma fuente que los gráficos del dashboard).
            'stats' => $reports->loanPortfolio(),
        ]);
    }

    public function loanShow(Loan $loan): View
    {
        // `application` es la solicitud de la que salió, si nació de una: la ficha ofrece el enlace
        // al expediente. Se carga aquí para que la vista no lance su propia consulta.
        $loan->load(['customer', 'installments', 'payments', 'application']);

        return view('panel.loan', ['loan' => $loan]);
    }

    /**
     * Reparto.
     *
     * Además del listado se pasa el CUADRE POR REPARTIDOR: cuánto lleva cobrado cada motorista y no
     * ha entregado en caja. Es la pregunta del cierre del día, y hasta ahora no tenía respuesta en
     * ninguna pantalla: ese efectivo salía por la puerta y no aparecía en ningún sitio.
     */
    public function deliveries(): View
    {
        $abiertas = DeliveryStatus::abiertas();

        return view('panel.deliveries', [
            'deliveries' => Delivery::query()
                ->with(['employee', 'sale'])
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('code', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")
                        ->orWhereLike('driver_name', "%{$q}%")->orWhereLike('address', "%{$q}%")
                ))
                ->when(
                    request('estado') && DeliveryStatus::tryFrom((string) request('estado')),
                    fn ($query) => $query->where('status', request('estado')),
                )
                ->latest()->paginate(15)->withQueryString(),

            'statuses' => DeliveryStatus::cases(),
            'estadoActivo' => request('estado'),
            'abiertas' => Delivery::query()->whereIn('status', $abiertas)->count(),

            // Solo los empleados activos: no tiene sentido ofrecer como repartidor a quien ya no está.
            'drivers' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),

            'porLiquidar' => Delivery::query()
                ->selectRaw('employee_id, count(*) as entregas, sum(amount_to_collect) as total')
                ->whereNotNull('collected_at')
                ->whereNull('settled_at')
                ->whereNotNull('employee_id')
                ->groupBy('employee_id')
                ->with('employee')
                ->get(),
        ]);
    }

    public function employees(): View
    {
        return view('panel.employees', [
            'employees' => Employee::query()->withCount('attendances')
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('name', "%{$q}%")->orWhereLike('email', "%{$q}%")->orWhereLike('position', "%{$q}%")
                ))
                ->orderBy('name')->paginate(15)->withQueryString(),
        ]);
    }

    public function ai(): View
    {
        return view('panel.ai', [
            'documents' => AiDocument::query()->withCount('chunks')->latest()->get(),
            'sentiments' => AiSentimentAnalysis::query()->latest()->take(15)->get(),
        ]);
    }

    public function account(CurrentCompany $currentCompany, PolarCheckoutService $checkout): View
    {
        $company = $currentCompany->model();
        $subscription = $company?->subscription;
        $plan = $subscription?->plan;

        return view('panel.account', [
            'company' => $company,
            'subscription' => $subscription,

            // Se puede pagar en línea solo si hay pasarela configurada y el plan está enlazado con
            // su producto. Si falta cualquiera de las dos, la pantalla ofrece contacto y ya está:
            // más vale no enseñar un botón que lleva a un callejón sin salida.
            'canPayOnline' => $plan !== null && $plan->isPurchasable() && $checkout->isConfigured(),

            'supportWhatsapp' => (string) config('platform.support_whatsapp'),
            'supportEmail' => (string) config('platform.support_email'),
            'supportPaypal' => (string) config('platform.support_paypal'),
        ]);
    }

    public function users(CurrentCompany $currentCompany): View
    {
        // Los usuarios no llevan CompanyScope: se aíslan aquí por la empresa activa. Se excluye
        // al super admin, que no pertenece a ninguna empresa.
        $users = User::query()
            ->where('company_id', $currentCompany->id())
            ->where('is_super_admin', false)
            ->with('roles')
            ->orderBy('name')
            ->paginate(20);

        // De quién es cada cuenta. Se carga el mapa de una vez en vez de una consulta por fila.
        $vinculos = Employee::query()
            ->whereNotNull('user_id')
            ->get(['id', 'name', 'user_id'])
            ->keyBy('user_id');

        return view('panel.users', [
            'users' => $users,
            'roles' => RoleCatalog::assignable(),
            // Los que aún no tienen cuenta: ofrecer uno ya vinculado solo sirve para robarle el
            // acceso a otro, y la validación lo rechazaría de todos modos.
            'employees' => Employee::query()
                ->where('is_active', true)->whereNull('user_id')
                ->orderBy('name')->get(['id', 'name', 'position']),
            'vinculos' => $vinculos,
        ]);
    }

    public function reports(ReportService $reports): View
    {
        $from = request()->filled('from')
            ? rescue(fn () => Carbon::parse((string) request('from')), Carbon::now()->subDays(29), report: false)
            : Carbon::now()->subDays(29);
        $to = request()->filled('to')
            ? rescue(fn () => Carbon::parse((string) request('to')), Carbon::now(), report: false)
            : Carbon::now();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return view('panel.reports', [
            'summary' => $reports->executiveSummary(),
            'report' => $reports->salesReport($from, $to),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }
}
