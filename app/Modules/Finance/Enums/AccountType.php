<?php

declare(strict_types=1);

namespace App\Modules\Finance\Enums;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Bank => 'Banco',
        };
    }
}
