<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Exceptions\CashSessionException;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gestión de sesiones y movimientos de caja. Toda entrada/salida de efectivo pasa por aquí,
 * de modo que el arqueo (esperado vs contado) siempre cuadra con el histórico de movimientos.
 * Aritmética decimal exacta con bcmath.
 */
final class CashService
{
    private const SCALE = 2;

    /**
     * Abre una sesión de caja con un fondo inicial. Solo puede haber una sesión abierta por caja.
     */
    public function open(CashRegister $register, string $openingAmount = '0', ?int $userId = null): CashSession
    {
        $hasOpen = CashSession::query()
            ->where('cash_register_id', $register->id)
            ->where('status', CashSessionStatus::Open)
            ->exists();

        if ($hasOpen) {
            throw CashSessionException::alreadyOpen($register->id);
        }

        return CashSession::create([
            'company_id' => $register->company_id,
            'cash_register_id' => $register->id,
            'user_id' => $userId ?? auth()->id(),
            'status' => CashSessionStatus::Open,
            'opening_amount' => $openingAmount,
            'opened_at' => now(),
        ]);
    }

    /**
     * Registra un movimiento en una sesión abierta. `amount` se recibe en positivo; el signo lo
     * determina el tipo.
     *
     * @param  array<string, mixed>  $context  claves opcionales: reference (Model), notes (string), userId (int)
     */
    public function registerMovement(
        CashSession $session,
        CashMovementType $type,
        string $amount,
        array $context = [],
    ): CashMovement {
        if (! $session->isOpen()) {
            throw CashSessionException::notOpen();
        }

        $signed = $type->direction() === 1
            ? $amount
            : bcmul($amount, '-1', self::SCALE);

        $reference = $context['reference'] ?? null;

        $movement = new CashMovement([
            'company_id' => $session->company_id,
            'cash_session_id' => $session->id,
            'type' => $type,
            'amount' => $signed,
            'user_id' => $context['userId'] ?? auth()->id(),
            'notes' => $context['notes'] ?? null,
        ]);

        if ($reference instanceof Model) {
            $movement->reference()->associate($reference);
        }

        $movement->save();

        return $movement;
    }

    /**
     * Cierra la sesión calculando el esperado (fondo + movimientos) y la diferencia con lo contado.
     */
    public function close(CashSession $session, string $countedAmount, ?string $notes = null): CashSession
    {
        if (! $session->isOpen()) {
            throw CashSessionException::notOpen();
        }

        return DB::transaction(function () use ($session, $countedAmount, $notes): CashSession {
            $movementsTotal = (string) $session->movements()->sum('amount');
            $expected = bcadd((string) $session->opening_amount, $movementsTotal, self::SCALE);
            $difference = bcsub($countedAmount, $expected, self::SCALE);

            $session->update([
                'status' => CashSessionStatus::Closed,
                'expected_amount' => $expected,
                'counted_amount' => $countedAmount,
                'difference' => $difference,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

            return $session;
        });
    }
}
