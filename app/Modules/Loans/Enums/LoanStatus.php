<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanStatus: string
{
    case Active = 'active';       // vigente (puede estar al día o con cuotas vencidas)
    case Paid = 'paid';           // saldado
    case Cancelled = 'cancelled'; // anulado

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Vigente',
            self::Paid => 'Saldado',
            self::Cancelled => 'Anulado',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-blue',
            self::Paid => 'badge-green',
            self::Cancelled => 'badge-gray',
        };
    }
}
