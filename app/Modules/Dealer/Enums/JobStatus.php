<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/** Si el trabajo de preparación ya se hizo o está pendiente. */
enum JobStatus: string
{
    case Pending = 'pending';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Done => 'Hecho',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Done => 'badge-green',
        };
    }
}
