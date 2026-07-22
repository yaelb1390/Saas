<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum OpportunityStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Won => 'Ganada',
            self::Lost => 'Perdida',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
