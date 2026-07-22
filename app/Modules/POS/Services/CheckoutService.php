<?php

declare(strict_types=1);

namespace App\Modules\POS\Services;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * Motor del punto de venta. Orquesta el checkout combinando dos módulos:
 *   1) Sales  → registra la venta y descuenta stock (vía StockService).
 *   2) Cash   → registra el cobro en la sesión de caja abierta.
 *
 * Todo ocurre en una única transacción: si falla el stock o la caja, no queda venta a medias.
 * POS depende de Sales y Cash; ninguno de ellos depende de POS.
 */
final class CheckoutService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CashService $cash,
    ) {}

    public function checkout(CashSession $session, CreateSaleData $data): Sale
    {
        return DB::transaction(function () use ($session, $data): Sale {
            // Lo único que el POS añade a la venta es la sesión de caja en la que se cobra.
            $sale = $this->sales->complete($data->withCashSession((int) $session->id));

            $this->cash->registerMovement(
                $session,
                CashMovementType::Sale,
                (string) $sale->total,
                ['reference' => $sale, 'notes' => "Cobro venta {$sale->code}"],
            );

            return $sale;
        });
    }
}
