<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Enums\PayableStatus;
use App\Modules\Finance\Events\PayablePaymentRegistered;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\PayablePayment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Alta y abonos de cuentas por pagar. Mismo motor que `ReceivableService` (y que `LoanService`):
 * bcmath a 2 decimales, transacción + `lockForUpdate()` en cada cambio de saldo.
 */
final class PayableService
{
    private const SCALE = 2;

    /** Días de crédito si ni el proveedor ni la petición dicen otra cosa. */
    private const PLAZO_POR_OMISION = 30;

    /**
     * Alta manual: para una deuda suelta que no viene de una orden de compra recibida.
     *
     * @param  array<string, mixed>  $datos  supplier_id?, supplier_name?, total, due_date?, notes?
     */
    public function crear(array $datos): Payable
    {
        return DB::transaction(function () use ($datos): Payable {
            $companyId = app(CurrentCompany::class)->id() ?? 0;
            $proveedor = $this->resolveSupplier($datos['supplier_id'] ?? null, $companyId);

            $total = $this->normalize((string) ($datos['total'] ?? '0'));
            if (bccomp($total, '0', self::SCALE) <= 0) {
                throw FinanceException::invalidPaymentAmount();
            }

            $cuenta = new Payable([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'supplier_id' => $proveedor?->id,
                'supplier_name' => $proveedor?->name ?? (string) ($datos['supplier_name'] ?? 'Proveedor'),
                'purchase_order_id' => null,
                'total' => $total,
                'balance' => $total,
                'due_date' => $datos['due_date'] ?? now()->addDays($proveedor?->payment_terms_days ?? self::PLAZO_POR_OMISION),
                'status' => PayableStatus::Pending,
                'notes' => $datos['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);
            $cuenta->save();

            return $cuenta;
        });
    }

    /**
     * El camino automático: una orden de compra se recibió. Si esa orden ya tiene su cuenta por
     * pagar (dos disparos del mismo evento, un reintento), no se duplica.
     */
    public function crearDesdeOrden(PurchaseOrder $order): Payable
    {
        return DB::transaction(function () use ($order): Payable {
            $existente = Payable::query()->where('purchase_order_id', $order->id)->first();

            if ($existente !== null) {
                throw FinanceException::purchaseOrderAlreadyHasPayable($existente->code);
            }

            $companyId = (int) $order->company_id;
            $proveedor = $order->supplier_id !== null
                ? Supplier::withoutCompanyScope()->where('company_id', $companyId)->find($order->supplier_id)
                : null;

            $total = (string) $order->total;

            $cuenta = new Payable([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'supplier_id' => $proveedor?->id,
                'supplier_name' => $proveedor?->name ?? 'Proveedor',
                'purchase_order_id' => $order->id,
                'total' => $total,
                'balance' => $total,
                'due_date' => now()->addDays($proveedor?->payment_terms_days ?? self::PLAZO_POR_OMISION),
                'status' => PayableStatus::Pending,
                'user_id' => $order->user_id,
            ]);
            $cuenta->save();

            return $cuenta;
        });
    }

    /**
     * Edita una cuenta por pagar. El monto (`total`) solo se puede tocar mientras no tenga ningún
     * abono: cambiarlo después dejaría un saldo que no cuadra con lo que ya se pagó.
     *
     * @param  array<string, mixed>  $datos  supplier_id?, supplier_name?, total?, due_date?, notes?
     */
    public function actualizar(Payable $payable, array $datos): Payable
    {
        return DB::transaction(function () use ($payable, $datos): Payable {
            /** @var Payable $payable */
            $payable = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('total', $datos) && $datos['total'] !== null) {
                if ($payable->payments()->exists()) {
                    throw FinanceException::cannotEditTotalWithPayments();
                }

                $total = $this->normalize((string) $datos['total']);
                if (bccomp($total, '0', self::SCALE) <= 0) {
                    throw FinanceException::invalidPaymentAmount();
                }

                $payable->total = $total;
                $payable->balance = $total;
                $payable->status = PayableStatus::Pending;
            }

            if (array_key_exists('supplier_id', $datos)) {
                $proveedor = $this->resolveSupplier($datos['supplier_id'] ?? null, (int) $payable->company_id);
                $payable->supplier_id = $proveedor?->id;

                if ($proveedor !== null) {
                    $payable->supplier_name = $proveedor->name;
                }
            }

            if (filled($datos['supplier_name'] ?? null) && $payable->supplier_id === null) {
                $payable->supplier_name = (string) $datos['supplier_name'];
            }

            if (array_key_exists('due_date', $datos)) {
                $payable->due_date = $datos['due_date'];
            }

            if (array_key_exists('notes', $datos)) {
                $payable->notes = $datos['notes'];
            }

            $payable->save();

            return $payable;
        });
    }

    /**
     * Elimina una cuenta por pagar. Solo si todavía no tiene abonos: uno ya registrado es
     * historial de dinero que salió de verdad, y borrarlo lo haría desaparecer sin rastro.
     */
    public function eliminar(Payable $payable): void
    {
        if ($payable->payments()->exists()) {
            throw FinanceException::hasPayments();
        }

        $payable->delete();
    }

    /**
     * Registra un abono: baja el saldo y, si llega a cero, la cuenta queda saldada.
     *
     * @param  array{account_id: int, method?: ?string, note?: ?string}  $context
     */
    public function registerPayment(Payable $payable, string $amount, array $context): PayablePayment
    {
        return DB::transaction(function () use ($payable, $amount, $context): PayablePayment {
            /** @var Payable $payable */
            $payable = Payable::query()->whereKey($payable->id)->lockForUpdate()->firstOrFail();
            $amount = $this->normalize($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw FinanceException::invalidPaymentAmount();
            }
            if (! $payable->status->canBePaid()) {
                throw FinanceException::alreadySettled();
            }
            if (bccomp($amount, (string) $payable->balance, self::SCALE) > 0) {
                throw FinanceException::paymentExceedsBalance((string) $payable->balance);
            }

            $payable->balance = bcsub((string) $payable->balance, $amount, self::SCALE);
            $payable->status = bccomp((string) $payable->balance, '0', self::SCALE) <= 0
                ? PayableStatus::Paid
                : PayableStatus::Partial;

            if ($payable->status === PayableStatus::Paid) {
                $payable->balance = '0.00';
            }

            $payable->save();

            $payment = new PayablePayment([
                'company_id' => $payable->company_id,
                'payable_id' => $payable->id,
                'account_id' => $context['account_id'],
                'amount' => $amount,
                'balance_after' => $payable->balance,
                'method' => $context['method'] ?? null,
                'note' => $context['note'] ?? null,
                'paid_at' => now(),
                'user_id' => auth()->id(),
            ]);
            $payment->save();

            PayablePaymentRegistered::dispatch($payment);

            return $payment;
        });
    }

    private function resolveSupplier(?int $supplierId, int $companyId): ?Supplier
    {
        if ($supplierId === null) {
            return null;
        }

        /** @var Supplier|null $proveedor */
        $proveedor = Supplier::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($supplierId)
            ->first();

        if ($proveedor === null) {
            throw FinanceException::supplierNotInCompany();
        }

        return $proveedor;
    }

    private function nextCode(int $companyId): string
    {
        $count = Payable::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'CXP-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
