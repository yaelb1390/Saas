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

    public function complete(CreateSaleData $data): Sale
    {
        return DB::transaction(function () use ($data): Sale {
            $warehouse = Warehouse::findOrFail($data->warehouseId);
            $companyId = (int) $warehouse->company_id;

            // El ITBIS se extrae de la base (ya con descuentos aplicados): los precios lo incluyen.
            $amounts = $this->tax->breakdown($data->gross());

            // La propina se suma DESPUÉS del ITBIS: no forma parte de la base imponible.
            $tip = bccomp($data->tip, '0', self::SCALE) > 0 ? $data->tip : '0.00';
            $total = bcadd($amounts['total'], $tip, self::SCALE);
            $paid = $data->paid ?? $total;

            if (bccomp($paid, $total, self::SCALE) < 0) {
                throw InsufficientPaymentException::for($total, $paid);
            }

            $customer = $this->resolveCustomer($data->customerId, $companyId);

            $sale = new Sale([
                'company_id' => $companyId,
                'customer_id' => $customer?->id,
                'branch_id' => $data->branchId ?? $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'cash_session_id' => $data->cashSessionId,
                'code' => $this->nextCode($companyId),
                'status' => SaleStatus::Completed,
                'customer_name' => $data->customerName ?? $customer?->name,
                'subtotal' => $amounts['subtotal'],
                'tax' => $amounts['tax'],
                'total' => $total,
                'tip' => $tip,
                'discount_total' => $data->discountTotal,
                'paid' => $paid,
                'change' => bcsub($paid, $total, self::SCALE),
                'payment_method' => $data->paymentMethod,
                'completed_at' => now(),
                'user_id' => auth()->id(),
                'employee_id' => $data->employeeId,
            ]);
            $sale->save();

            foreach ($data->lines as $line) {
                $product = Product::findOrFail($line->productId);

                $sale->items()->create([
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

                if ($product->track_stock) {
                    $this->stock->decrease(
                        $product,
                        $warehouse,
                        StockMovementType::Sale,
                        $line->quantity,
                        ['reference' => $sale, 'notes' => "Venta {$sale->code}"],
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

    private function nextCode(int $companyId): string
    {
        $count = Sale::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->count();

        return 'V-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
