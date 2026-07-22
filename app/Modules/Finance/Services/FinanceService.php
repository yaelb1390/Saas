<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Registra movimientos financieros y mantiene el balance de la cuenta de forma consistente
 * (transacción + bloqueo de fila + aritmética decimal exacta).
 */
final class FinanceService
{
    private const SCALE = 2;

    /**
     * @param  array<string, mixed>  $context  claves opcionales: reference (Model), occurredAt (\DateTimeInterface), userId (int)
     */
    public function record(
        Account $account,
        MovementType $type,
        string $amount,
        ?string $description = null,
        array $context = [],
    ): FinancialMovement {
        return DB::transaction(function () use ($account, $type, $amount, $description, $context): FinancialMovement {
            $locked = Account::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $signed = $type->direction() === 1 ? $amount : bcmul($amount, '-1', self::SCALE);
            $locked->balance = bcadd((string) $locked->balance, $signed, self::SCALE);
            $locked->save();

            $reference = $context['reference'] ?? null;

            $movement = new FinancialMovement([
                'company_id' => $locked->company_id,
                'account_id' => $locked->id,
                'type' => $type,
                'amount' => $signed,
                'description' => $description,
                'occurred_at' => $context['occurredAt'] ?? now(),
                'user_id' => $context['userId'] ?? auth()->id(),
            ]);

            if ($reference instanceof Model) {
                $movement->reference()->associate($reference);
            }

            $movement->save();

            return $movement;
        });
    }
}
