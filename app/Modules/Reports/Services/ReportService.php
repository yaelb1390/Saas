<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Enums\OpportunityStatus;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Finance\Models\Account;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Loans\Enums\InstallmentStatus;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanInstallment;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resumen ejecutivo: agrega indicadores de todos los módulos (solo lectura), ya aislados por la
 * empresa activa gracias al Global Scope. Es la vista transversal para el dashboard.
 */
final class ReportService
{
    private const LOW_STOCK_THRESHOLD = '5';

    /** Segundos que se sirve el resumen desde caché antes de recalcularlo. */
    private const SUMMARY_TTL = 60;

    /**
     * @return array{
     *     sales_total: string,
     *     sales_count: int,
     *     cash_balance: string,
     *     open_opportunities: int,
     *     pending_deliveries: int,
     *     products: int,
     *     low_stock: int,
     *     loans_outstanding: string,
     *     loans_count: int,
     *     loans_overdue: string,
     *     overdue_count: int,
     * }
     */
    public function executiveSummary(): array
    {
        // Son 7 agregaciones (sum/count) sobre tablas que crecen; recalcularlas en cada carga del
        // dashboard es el gasto más caro del panel. Un minuto de antigüedad es imperceptible para
        // un indicador de gestión y evita repetirlas en cada visita/refresco.
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:executive-summary",
            self::SUMMARY_TTL,
            fn (): array => $this->computeExecutiveSummary(),
        );
    }

    /**
     * Cálculo real del resumen (sin caché). Separado para poder cachearlo y para poder probar el
     * valor fresco.
     *
     * @return array{
     *     sales_total: string,
     *     sales_count: int,
     *     cash_balance: string,
     *     open_opportunities: int,
     *     pending_deliveries: int,
     *     products: int,
     *     low_stock: int,
     *     loans_outstanding: string,
     *     loans_count: int,
     *     loans_overdue: string,
     *     overdue_count: int,
     * }
     */
    public function computeExecutiveSummary(): array
    {
        return [
            'sales_total' => (string) Sale::query()->where('status', SaleStatus::Completed)->sum('total'),
            'sales_count' => Sale::query()->where('status', SaleStatus::Completed)->count(),
            'cash_balance' => (string) Account::query()->sum('balance'),
            'open_opportunities' => Opportunity::query()->where('status', OpportunityStatus::Open)->count(),
            'pending_deliveries' => Delivery::query()->whereIn('status', [
                DeliveryStatus::Pending,
                DeliveryStatus::Assigned,
                DeliveryStatus::InTransit,
            ])->count(),
            'products' => Product::query()->count(),
            'low_stock' => Stock::query()->where('quantity', '<', self::LOW_STOCK_THRESHOLD)->count(),
            // Cartera de préstamos: saldo vigente y lo que está vencido (cuota + mora − abonado).
            'loans_outstanding' => (string) Loan::query()->where('status', LoanStatus::Active)->sum('balance'),
            'loans_count' => Loan::query()->where('status', LoanStatus::Active)->count(),
            'loans_overdue' => (string) $this->overdueInstallments()->sum(DB::raw('amount + late_fee - paid_amount')),
            'overdue_count' => $this->overdueInstallments()->count(),
        ];
    }

    /**
     * Cuotas vencidas: no saldadas y con fecha ya pasada. Aisladas por empresa vía CompanyScope.
     *
     * @return Builder<LoanInstallment>
     */
    private function overdueInstallments()
    {
        return LoanInstallment::query()
            ->where('status', '!=', InstallmentStatus::Paid->value)
            ->whereDate('due_date', '<', Carbon::now()->toDateString());
    }

    /**
     * Reporte de ventas de un rango de fechas: totales, ticket promedio, ventas por día y los
     * productos más vendidos. Se agrega en PHP para ser portable entre PostgreSQL y SQLite.
     *
     * @return array{
     *     total: string,
     *     count: int,
     *     avg_ticket: string,
     *     days: array<string, float>,
     *     top_products: array<int, array{name: string, qty: string, total: string}>,
     * }
     */
    public function salesReport(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $sales = Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereBetween('created_at', [$from, $to])
            ->with(['items.product' => fn ($query) => $query->withTrashed()])
            ->get();

        $total = '0';
        foreach ($sales as $sale) {
            $total = bcadd($total, (string) $sale->total, 2);
        }
        $count = $sales->count();
        $avgTicket = $count > 0 ? bcdiv($total, (string) $count, 2) : '0.00';

        // Ventas por día (rellena los días sin ventas con 0).
        $byDay = [];
        foreach ($sales as $sale) {
            $key = $sale->created_at?->format('Y-m-d') ?? '';
            $byDay[$key] = ($byDay[$key] ?? 0.0) + (float) $sale->total;
        }
        $days = [];
        for ($cursor = $from->copy(); $cursor <= $to; $cursor->addDay()) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = round($byDay[$key] ?? 0.0, 2);
        }

        // Top productos por importe vendido.
        $agg = [];
        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $name = $item->product->name;
                $agg[$name] ??= ['qty' => '0', 'total' => '0'];
                $agg[$name]['qty'] = bcadd($agg[$name]['qty'], (string) $item->quantity, 3);
                $agg[$name]['total'] = bcadd($agg[$name]['total'], (string) $item->subtotal, 2);
            }
        }
        uasort($agg, fn ($a, $b) => bccomp($b['total'], $a['total'], 2));
        $topProducts = [];
        foreach (array_slice($agg, 0, 5, true) as $name => $data) {
            $topProducts[] = ['name' => $name, 'qty' => $data['qty'], 'total' => $data['total']];
        }

        return [
            'total' => $total,
            'count' => $count,
            'avg_ticket' => $avgTicket,
            'days' => $days,
            'top_products' => $topProducts,
        ];
    }
}
