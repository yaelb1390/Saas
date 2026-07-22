<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Support\Facades\Cache;

/**
 * Alertas operativas de la empresa activa (aisladas por el Global Scope): stock bajo, cajas sin
 * cerrar, secuencias fiscales en riesgo y entregas pendientes. Se muestran en la campana.
 */
final class AlertService
{
    private const LOW_STOCK_THRESHOLD = '5';

    /** Margen de NCF restantes por debajo del cual conviene solicitar una nueva secuencia. */
    private const LOW_NCF_THRESHOLD = 50;

    /** Segundos que se sirven las alertas desde caché antes de recalcularlas. */
    private const TTL = 60;

    /**
     * La campana se pinta en TODAS las páginas del panel, así que estas 4 consultas se ejecutaban en
     * cada carga (y dos de ellas repetían lo que ya calculaba el resumen del dashboard). Son avisos
     * operativos, no control de acceso: un minuto de antigüedad es inocuo.
     *
     * @return array<int, array{key: string, title: string, count: int, url: string, tone: string, icon: string}>
     */
    public function forCurrentCompany(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember("company:{$companyId}:alerts", self::TTL, fn (): array => $this->compute());
    }

    /**
     * Cálculo real de las alertas (sin caché).
     *
     * @return array<int, array{key: string, title: string, count: int, url: string, tone: string, icon: string}>
     */
    public function compute(): array
    {
        $alerts = [];

        $lowStock = Stock::query()->where('quantity', '<', self::LOW_STOCK_THRESHOLD)->count();
        if ($lowStock > 0) {
            $alerts[] = [
                'key' => 'low_stock',
                'title' => $lowStock === 1 ? '1 producto con stock bajo' : "{$lowStock} productos con stock bajo",
                'count' => $lowStock,
                'url' => route('panel.products'),
                'tone' => 'amber',
                'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
            ];
        }

        $openCash = CashSession::query()->where('status', CashSessionStatus::Open)->count();
        if ($openCash > 0) {
            $alerts[] = [
                'key' => 'open_cash',
                'title' => $openCash === 1 ? '1 caja abierta sin cerrar' : "{$openCash} cajas abiertas sin cerrar",
                'count' => $openCash,
                'url' => route('panel.pos'),
                'tone' => 'indigo',
                'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
            ];
        }

        // Fiscal: quedarse sin NCF (o con la secuencia vencida) impide facturar y paraliza la caja.
        $sequencesAtRisk = FiscalSequence::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (FiscalSequence $s): bool => $s->isExpired()
                || $s->range_to - $s->next_number + 1 <= self::LOW_NCF_THRESHOLD)
            ->count();

        if ($sequencesAtRisk > 0) {
            $alerts[] = [
                'key' => 'ncf_sequences',
                'title' => $sequencesAtRisk === 1
                    ? '1 secuencia de NCF agotándose o vencida'
                    : "{$sequencesAtRisk} secuencias de NCF agotándose o vencidas",
                'count' => $sequencesAtRisk,
                'url' => route('panel.invoices'),
                'tone' => 'rose',
                'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
            ];
        }

        $pendingDeliveries = Delivery::query()->whereIn('status', [
            DeliveryStatus::Pending,
            DeliveryStatus::Assigned,
            DeliveryStatus::InTransit,
        ])->count();
        if ($pendingDeliveries > 0) {
            $alerts[] = [
                'key' => 'pending_deliveries',
                'title' => $pendingDeliveries === 1 ? '1 entrega pendiente' : "{$pendingDeliveries} entregas pendientes",
                'count' => $pendingDeliveries,
                'url' => route('panel.deliveries'),
                'tone' => 'sky',
                'icon' => 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.66-.831H14.25',
            ];
        }

        return $alerts;
    }
}
