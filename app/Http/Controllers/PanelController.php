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
use App\Modules\Core\Support\RoleCatalog;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
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
            'products' => Product::query()->where('is_active', true)->with('stock')->orderBy('name')->get(),
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
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('sku', "%{$q}%")
                        ->orWhereLike('name', "%{$q}%")
                        ->orWhereLike('barcode', "%{$q}%")
                ))
                ->orderBy('name')->paginate(15)->withQueryString(),
            'categories' => Category::query()->orderBy('name')->get(),
            // Los datos de pieza de vehículo solo tienen sentido en un negocio de repuestos.
            'showPartFields' => $company !== null && PosProfile::for($company)['profile'] === 'repuestos',
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
     * Entrada de mercancía: escanear o teclear el código y sumar existencia.
     *
     * Los movimientos recientes se muestran como acuse de recibo: el almacenista necesita ver que
     * lo que acaba de pasar por el lector quedó registrado, y con qué saldo.
     */
    public function stockEntry(): View
    {
        return view('panel.stock-entry', [
            'warehouses' => Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'categories' => Category::query()->orderBy('name')->get(),
            'movements' => StockMovement::query()
                ->with(['product', 'warehouse'])
                ->latest('id')
                ->limit(15)
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

    public function deliveries(): View
    {
        return view('panel.deliveries', [
            'deliveries' => Delivery::query()
                ->when(request('q'), fn ($query, $q) => $query->where(
                    fn ($sub) => $sub->whereLike('code', "%{$q}%")->orWhereLike('customer_name', "%{$q}%")->orWhereLike('driver_name', "%{$q}%")
                ))
                ->latest()->paginate(15)->withQueryString(),
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

    public function account(CurrentCompany $currentCompany): View
    {
        $company = $currentCompany->model();

        return view('panel.account', [
            'company' => $company,
            'subscription' => $company?->subscription,
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

        return view('panel.users', [
            'users' => $users,
            'roles' => RoleCatalog::assignable(),
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
