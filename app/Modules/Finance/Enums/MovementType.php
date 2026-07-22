<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum MovementType: string
{
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Ingreso',
            self::Expense => 'Egreso',
        };
    }

    /**
     * +1 si suma al balance, -1 si resta.
     */
    public function direction(): int
    {
        return $this === self::Income ? 1 : -1;
    }
}
