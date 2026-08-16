<?php

declare(strict_types=1);

namespace App\Modules\POS\Services;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use App\Modules\HR\Models\Employee;
use App\Modules\POS\DTOs\DeliveryOrderData;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Support\Facades\DB;

/**
 * Motor del punto de venta. Orquesta el checkout combinando tres módulos:
 *   1) Sales    → registra la venta y descuenta stock (vía StockService).
 *   2) Cash     → registra el cobro en la sesión de caja abierta.
 *   3) Delivery → si el pedido es con envío, crea la entrega ya lista para salir.
 *
 * Todo ocurre en una única transacción: si falla el stock, la caja o la entrega, no queda venta a
 * medias. Eso último importa más de lo que parece: una venta cobrada sin su entrega es un pedido que
 * el cliente pagó y que nadie va a llevar a ninguna parte, y nada en pantalla lo delataría.
 *
 * POS depende de Sales, Cash y Delivery; ninguno de ellos depende de POS.
 */
final class CheckoutService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CashService $cash,
        private readonly DeliveryService $deliveries,
    ) {}

    public function checkout(CashSession $session, CreateSaleData $data, ?DeliveryOrderData $delivery = null): Sale
    {
        return DB::transaction(function () use ($session, $data, $delivery): Sale {
            // Lo único que el POS añade a la venta es la sesión de caja en la que se cobra.
            $sale = $this->sales->complete($data->withCashSession((int) $session->id));

            // Solo el efectivo entra al cajón. Una venta con tarjeta o transferencia queda ligada a
            // la sesión (para saber quién y cuándo la cobró) pero NO suma al arqueo: si sumara, el
            // cierre arrojaría un faltante exactamente igual a lo cobrado por esas vías, y el cajero
            // acabaría cuadrando a mano una diferencia que no existe.
            //
            // Un pedido que paga el cliente en la puerta va a crédito, que tampoco entra: ese dinero
            // llega cuando el motorista liquida, y ahí es donde se anota.
            if ($data->paymentMethod->entersCashDrawer()) {
                $this->cash->registerMovement(
                    $session,
                    CashMovementType::Sale,
                    (string) $sale->total,
                    ['reference' => $sale, 'notes' => "Cobro venta {$sale->code}"],
                );
            }

            if ($delivery !== null && $data->orderType?->generaEntrega()) {
                $this->crearEntrega($sale, $delivery);
            }

            return $sale;
        });
    }

    /**
     * La entrega del pedido, con lo que el cajero acaba de teclear.
     *
     * El monto a cobrar sale del TOTAL DE LA VENTA y no de nada que llegue del navegador: es dinero
     * que alguien va a reclamar en una puerta, y el único sitio donde ya está calculado bien —con su
     * ITBIS, sus descuentos y sus opciones— es la venta recién registrada.
     */
    private function crearEntrega(Sale $sale, DeliveryOrderData $datos): Delivery
    {
        $entrega = $this->deliveries->create(
            address: $datos->address,
            customerName: $datos->customerName,
            phone: $datos->phone,
            sale: $sale,
            amountToCollect: $datos->cobraElMotorista ? (string) $sale->total : '0',
            notes: $datos->notes,
        );

        if ($datos->sinAsignar) {
            return $entrega;
        }

        // Un repartidor elegido a mano manda sobre el automático. Si ya no está activo se ignora y se
        // busca otro: colgarle el pedido a quien no está de servicio lo dejaría parado sin que nadie
        // se enterase.
        $elegido = $datos->employeeId === null
            ? null
            : Employee::query()->where('is_active', true)->find($datos->employeeId);

        return $elegido !== null
            ? $this->deliveries->assign($entrega, $elegido)
            : $this->deliveries->assignAutomatically($entrega);
    }
}
