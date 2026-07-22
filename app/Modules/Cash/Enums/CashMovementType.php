<?php

declare(strict_types=1);

namespace App\Modules\Cash\Enums;

/**
 * Tipos de movimiento de caja. `direction()` indica el signo con que afectan el saldo.
 */
enum CashMovementType: string
{
    case Sale = 'sale';           // cobro de una venta (+)
    case Income = 'income';       // otro ingreso (+)
    case Deposit = 'deposit';     // aporte a caja (+)
    case Expense = 'expense';     // gasto pagado desde caja (-)
    case Withdrawal = 'withdrawal'; // retiro de efectivo (-)

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Venta',
            self::Income => 'Ingreso',
            self::Deposit => 'Depósito',
            self::Expense => 'Gasto',
            self::Withdrawal => 'Retiro',
        };
    }

    /**
     * +1 si suma al saldo de caja, -1 si resta.
     */
    public function direction(): int
    {
        return match ($this) {
            self::Sale, self::Income, self::Deposit => 1,
            self::Expense, self::Withdrawal => -1,
        };
    }
}
