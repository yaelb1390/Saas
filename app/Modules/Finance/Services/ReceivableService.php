<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Finance\Enums\ReceivableStatus;
use App\Modules\Finance\Events\ReceivablePaymentRegistered;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\ReceivablePayment;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Alta y abonos de cuentas por cobrar. Calca `LoanService`: todo el dinero se maneja con bcmath a
 * 2 decimales, nunca float, y el saldo se toca siempre dentro de una transacción con
 * `lockForUpdate()` para que dos abonos simultáneos no se pisen.
 */
final class ReceivableService
{
    private const SCALE = 2;

    /** Días de crédito si ni el cliente ni la petición dicen otra cosa. */
    private const PLAZO_POR_OMISION = 30;

    /**
     * Alta manual: para una deuda suelta que no viene de una venta del sistema (ver
     * `crearDesdeVenta()` para el camino automático).
     *
     * @param  array<string, mixed>  $datos  customer_id?, customer_name?, total, due_date?, notes?
     */
    public function crear(array $datos): Receivable
    {
        return DB::transaction(function () use ($datos): Receivable {
            $companyId = app(CurrentCompany::class)->id() ?? 0;
            $cliente = $this->resolveCustomer($datos['customer_id'] ?? null, $companyId);

            $total = $this->normalize((string) ($datos['total'] ?? '0'));
            if (bccomp($total, '0', self::SCALE) <= 0) {
                throw FinanceException::invalidPaymentAmount();
            }

            $cuenta = new Receivable([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'customer_id' => $cliente?->id,
                'customer_name' => $cliente?->name ?? (string) ($datos['customer_name'] ?? 'Cliente'),
                'sale_id' => null,
                'total' => $total,
                'balance' => $total,
                'due_date' => $datos['due_date'] ?? now()->addDays($cliente?->payment_terms_days ?? self::PLAZO_POR_OMISION),
                'status' => ReceivableStatus::Pending,
                'notes' => $datos['notes'] ?? null,
                'user_id' => auth()->id(),
            ]);
            $cuenta->save();

            return $cuenta;
        });
    }

    /**
     * El camino automático: una venta se completó sin cobrarse del todo. Si esa venta ya tiene su
     * cuenta por cobrar (dos disparos del mismo evento, un reintento), no se duplica.
     */
    public function crearDesdeVenta(Sale $sale, string $pendiente): Receivable
    {
        return DB::transaction(function () use ($sale, $pendiente): Receivable {
            $existente = Receivable::query()->where('sale_id', $sale->id)->first();

            if ($existente !== null) {
                throw FinanceException::saleAlreadyHasReceivable($existente->code);
            }

            $companyId = (int) $sale->company_id;
            $cliente = $sale->customer_id !== null
                ? Customer::withoutCompanyScope()->where('company_id', $companyId)->find($sale->customer_id)
                : null;

            $pendiente = $this->normalize($pendiente);

            $cuenta = new Receivable([
                'company_id' => $companyId,
                'code' => $this->nextCode($companyId),
                'customer_id' => $cliente?->id,
                'customer_name' => $cliente?->name ?? $sale->customer_name ?? 'Cliente',
                'sale_id' => $sale->id,
                'total' => $pendiente,
                'balance' => $pendiente,
                'due_date' => now()->addDays($cliente?->payment_terms_days ?? self::PLAZO_POR_OMISION),
                'status' => ReceivableStatus::Pending,
                'user_id' => $sale->user_id,
            ]);
            $cuenta->save();

            return $cuenta;
        });
    }

    /**
     * Edita una cuenta por cobrar. El monto (`total`) solo se puede tocar mientras no tenga ningún
     * abono: cambiarlo después dejaría un saldo que no cuadra con lo que ya se cobró.
     *
     * @param  array<string, mixed>  $datos  customer_id?, customer_name?, total?, due_date?, notes?
     */
    public function actualizar(Receivable $receivable, array $datos): Receivable
    {
        return DB::transaction(function () use ($receivable, $datos): Receivable {
            /** @var Receivable $receivable */
            $receivable = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('total', $datos) && $datos['total'] !== null) {
                if ($receivable->payments()->exists()) {
                    throw FinanceException::cannotEditTotalWithPayments();
                }

                $total = $this->normalize((string) $datos['total']);
                if (bccomp($total, '0', self::SCALE) <= 0) {
                    throw FinanceException::invalidPaymentAmount();
                }

                $receivable->total = $total;
                $receivable->balance = $total;
                $receivable->status = ReceivableStatus::Pending;
            }

            if (array_key_exists('customer_id', $datos)) {
                $cliente = $this->resolveCustomer($datos['customer_id'] ?? null, (int) $receivable->company_id);
                $receivable->customer_id = $cliente?->id;

                if ($cliente !== null) {
                    $receivable->customer_name = $cliente->name;
                }
            }

            if (filled($datos['customer_name'] ?? null) && $receivable->customer_id === null) {
                $receivable->customer_name = (string) $datos['customer_name'];
            }

            if (array_key_exists('due_date', $datos)) {
                $receivable->due_date = $datos['due_date'];
            }

            if (array_key_exists('notes', $datos)) {
                $receivable->notes = $datos['notes'];
            }

            $receivable->save();

            return $receivable;
        });
    }

    /**
     * Elimina una cuenta por cobrar. Solo si todavía no tiene abonos: uno ya registrado es
     * historial de dinero que entró de verdad, y borrarlo lo haría desaparecer sin rastro.
     */
    public function eliminar(Receivable $receivable): void
    {
        if ($receivable->payments()->exists()) {
            throw FinanceException::hasPayments();
        }

        $receivable->delete();
    }

    /**
     * Registra un abono: baja el saldo y, si llega a cero, la cuenta queda saldada.
     *
     * @param  array{account_id: int, method?: ?string, note?: ?string}  $context
     */
    public function registerPayment(Receivable $receivable, string $amount, array $context): ReceivablePayment
    {
        return DB::transaction(function () use ($receivable, $amount, $context): ReceivablePayment {
            /** @var Receivable $receivable */
            $receivable = Receivable::query()->whereKey($receivable->id)->lockForUpdate()->firstOrFail();
            $amount = $this->normalize($amount);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                throw FinanceException::invalidPaymentAmount();
            }
            if (! $receivable->status->canBePaid()) {
                throw FinanceException::alreadySettled();
            }
            if (bccomp($amount, (string) $receivable->balance, self::SCALE) > 0) {
                throw FinanceException::paymentExceedsBalance((string) $receivable->balance);
            }

            $receivable->balance = bcsub((string) $receivable->balance, $amount, self::SCALE);
            $receivable->status = bccomp((string) $receivable->balance, '0', self::SCALE) <= 0
                ? ReceivableStatus::Paid
                : ReceivableStatus::Partial;

            if ($receivable->status === ReceivableStatus::Paid) {
                $receivable->balance = '0.00';
            }

            $receivable->save();

            $payment = new ReceivablePayment([
                'company_id' => $receivable->company_id,
                'receivable_id' => $receivable->id,
                'account_id' => $context['account_id'],
                'amount' => $amount,
                'balance_after' => $receivable->balance,
                'method' => $context['method'] ?? null,
                'note' => $context['note'] ?? null,
                'paid_at' => now(),
                'user_id' => auth()->id(),
            ]);
            $payment->save();

            ReceivablePaymentRegistered::dispatch($payment);

            return $payment;
        });
    }

    private function resolveCustomer(?int $customerId, int $companyId): ?Customer
    {
        if ($customerId === null) {
            return null;
        }

        /** @var Customer|null $cliente */
        $cliente = Customer::withoutCompanyScope()
            ->where('company_id', $companyId)
            ->whereKey($customerId)
            ->first();

        if ($cliente === null) {
            throw FinanceException::customerNotInCompany();
        }

        return $cliente;
    }

    /**
     * `withTrashed()` porque una cuenta por cobrar se archiva, no se destruye: sin contar las
     * archivadas, la siguiente reutilizaría un código ya usado y chocaría contra el índice único.
     */
    private function nextCode(int $companyId): string
    {
        $count = Receivable::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'CXC-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function normalize(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }
}
