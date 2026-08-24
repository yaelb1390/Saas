<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Support\TaxCalculator;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Events\SaleCompleted;
use App\Modules\Sales\Exceptions\CustomerNotInCompanyException;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de ventas. Al completar una venta descuenta el stock del almacén mediante
 * StockService (comunicación servicio a servicio: Ventas depende de Inventario). Si algún
 * producto no tiene existencia suficiente, StockService aborta y la transacción hace rollback.
 */
final class SaleService
{
    private const SCALE = 2;

    public function __construct(
        private readonly StockService $stock,
        private readonly TaxCalculator $tax,
    ) {}

    /**
     * @param  bool  $permitirStockNegativo  dejar la existencia por debajo de cero en vez de rechazar
     *                                       la venta. Solo lo enciende la subida de ventas cobradas
     *                                       sin internet: ahí la mercancía ya se la llevó el cliente,
     *                                       y rechazar la salida no la devuelve, solo hace que el
     *                                       inventario mienta al alza. Va como parámetro de la
     *                                       OPERACIÓN y no dentro del DTO porque no describe la
     *                                       venta, sino cómo se permite registrarla.
     */
    public function complete(CreateSaleData $data, bool $permitirStockNegativo = false): Sale
    {
        return DB::transaction(function () use ($data, $permitirStockNegativo): Sale {
            $warehouse = Warehouse::findOrFail($data->warehouseId);
            $companyId = (int) $warehouse->company_id;

            // El ITBIS se extrae de la base (ya con descuentos aplicados): los precios lo incluyen.
            $amounts = $this->tax->breakdown($data->gross());

            // La propina se suma DESPUÉS del ITBIS: no forma parte de la base imponible.
            $tip = bccomp($data->tip, '0', self::SCALE) > 0 ? $data->tip : '0.00';
            $total = bcadd($amounts['total'], $tip, self::SCALE);
            $paid = $data->paid ?? $total;

            /*
             * El pago tiene que cubrir el total... salvo a crédito, que es precisamente «se cobra
             * después»: un pedido a domicilio que paga el cliente en la puerta.
             *
             * La excepción se ata a la forma de pago y no a un interruptor suelto para que no se
             * pueda registrar por descuido una venta de mostrador a medio pagar. Y `entersCashDrawer()`
             * ya devuelve false para crédito, así que ese dinero no toca el arqueo: el turno cuadra sin
             * que haya que acordarse de nada.
             */
            if ($data->paymentMethod !== PaymentMethod::Credit && bccomp($paid, $total, self::SCALE) < 0) {
                throw InsufficientPaymentException::for($total, $paid);
            }

            // A crédito no hay vuelto que dar: lo pendiente no es un cambio a favor del cliente.
            $change = $data->paymentMethod === PaymentMethod::Credit
                ? '0.00'
                : bcsub($paid, $total, self::SCALE);

            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $sale = new Sale([
                'company_id' => $companyId,
                'customer_id' => $customer?->id,
                'branch_id' => $data->branchId ?? $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'cash_session_id' => $data->cashSessionId,
                'code' => $this->nextCode($companyId),
                'client_uuid' => $data->clientUuid,
                'status' => SaleStatus::Completed,
                'order_type' => $data->orderType?->value,
                'customer_name' => $data->customerName ?? $customer?->name,
                'subtotal' => $amounts['subtotal'],
                'tax' => $amounts['tax'],
                'total' => $total,
                'tip' => $tip,
                'discount_total' => $data->discountTotal,
                'paid' => $paid,
                'change' => $change,
                'payment_method' => $data->paymentMethod->value,
                'completed_at' => now(),
                'user_id' => auth()->id(),
                'employee_id' => $data->employeeId,
            ]);
            $sale->save();

            foreach ($data->lines as $line) {
                $product = Product::findOrFail($line->productId);

                $item = $sale->items()->create([
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'discount' => $line->discount,
                    // Importe de la línea con ITBIS incluido y descuento aplicado: lo que ve el cliente.
                    'subtotal' => $line->amount(),
                    'note' => $line->note,
                    'serial' => $line->serial,
                    'employee_id' => $line->employeeId,
                ]);

                // Tamaños, sabores y extras elegidos. Se copian los nombres y el recargo tal como
                // estaban al vender: un recibo es un documento del pasado y no puede cambiar porque
                // alguien renombre una opción o suba su precio más adelante.
                //
                // El recargo NO se vuelve a sumar aquí: ya está dentro de `unitPrice`, que calculó
                // el servidor. Sumarlo otra vez cobraría el extra dos veces.
                foreach ($line->options as $option) {
                    $item->options()->create([
                        'company_id' => $companyId,
                        'option_id' => $option->optionId,
                        'group_name' => $option->groupName,
                        'option_name' => $option->optionName,
                        'price_delta' => $option->priceDelta,
                    ]);
                }

                if ($product->track_stock) {
                    $this->stock->decrease(
                        $product,
                        $warehouse,
                        StockMovementType::Sale,
                        $line->quantity,
                        ['reference' => $sale, 'notes' => "Venta {$sale->code}"],
                        $permitirStockNegativo,
                    );
                }
            }

            SaleCompleted::dispatch($sale);

            return $sale->load('items');
        });
    }

    /**
     * Resuelve el cliente al que se le vende, exigiendo que sea de la misma empresa que la venta.
     *
     * La empresa sale del almacén, no del contexto de sesión, así que aquí se comprueba la
     * pertenencia de forma explícita en vez de confiar en el CompanyScope: el customerId llega del
     * cliente HTTP y, sin esta verificación, un id de otra empresa quedaría enlazado a la venta y
     * expondría esa venta en el portal de un cliente ajeno.
     */
    private function resolveCustomer(?int $customerId, int $companyId): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        /** @var Customer|null $customer */
        $customer = Customer::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw CustomerNotInCompanyException::for($customerId, $companyId);
        }

        return $customer;
    }

    /**
     * Código correlativo de la venta.
     *
     * `withTrashed()` NO es opcional: las ventas anuladas se archivan (borrado lógico) y sin esto
     * dejarían de contarse, así que la siguiente venta reutilizaría el código de la anulada y
     * chocaría contra el índice único de `(company_id, code)`. En la práctica: anulabas una venta y
     * la siguiente NO SE PODÍA COBRAR.
     *
     * Contar filas sigue teniendo un límite conocido: dos cajeros cobrando en el mismo instante
     * cuentan lo mismo y uno de los dos choca. El índice único evita el código duplicado —que es lo
     * grave—, pero esa venta habría que repetirla. Resolverlo bien pide un contador por empresa con
     * bloqueo, no un `count()`.
     */
    private function nextCode(int $companyId): string
    {
        $count = Sale::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'V-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
