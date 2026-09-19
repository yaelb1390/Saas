<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Support\Facades\Cache;

/**
 * Las cuatro cifras de la franja de resumen de Inventario.
 *
 * Son 4 agregaciones sobre TODO el catálogo de la empresa (no la página de 15 que se ve), así que
 * recalcularlas en cada visita/paginación/filtro es el gasto más caro de esta pantalla — mismo
 * problema, y misma solución, que ya tiene el resumen ejecutivo del dashboard (ver
 * ReportService::executiveSummary()). Un minuto de antigüedad es imperceptible para una cifra de
 * gestión.
 */
final class ProductSummaryService
{
    /** Segundos que se sirve el resumen desde caché antes de recalcularlo. */
    private const TTL = 60;

    /**
     * @return array{total: int, stockTotal: string, valorInventario: string, bajoStock: int}
     */
    public function resumen(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;

        return Cache::remember(
            "company:{$companyId}:products-resumen",
            self::TTL,
            fn (): array => $this->computeResumen(),
        );
    }

    /**
     * Cálculo real del resumen (sin caché). Separado para poder cachearlo y para poder probar el
     * valor fresco.
     *
     * @return array{total: int, stockTotal: string, valorInventario: string, bajoStock: int}
     */
    public function computeResumen(): array
    {
        return [
            'total' => Product::query()->count(),
            'stockTotal' => (string) Stock::query()
                ->whereHas('product', fn ($q) => $q->where('track_stock', true))
                ->sum('quantity'),
            'valorInventario' => (string) Stock::query()
                ->join('products', 'products.id', '=', 'stock.product_id')
                ->where('products.track_stock', true)
                ->selectRaw('COALESCE(SUM(stock.quantity * products.cost), 0) as total')
                ->value('total'),
            // Reutiliza Product::scopeStockBajo() a propósito: es la ÚNICA definición correcta de
            // «stock bajo» del sistema (ver el comentario en el modelo). Esta tarjeta no inventa
            // una cuarta forma de contarlo.
            'bajoStock' => Product::query()->stockBajo()->count(),
        ];
    }
}
