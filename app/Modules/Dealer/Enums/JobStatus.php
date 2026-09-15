<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Enums;

/**
 * Si el trabajo de preparación ya se hizo o está pendiente.
 *
 * `Scheduled` e `InProgress` se añadieron para que este mismo registro sirva también como
 * mantenimiento PROGRAMADO (fecha/kilometraje del próximo cambio, ver las columnas `next_due_at` y
 * `next_mileage`) y no solo como «gasto ya hecho». `Pending`/`Done` siguen significando exactamente
 * lo mismo que antes.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Scheduled => 'Programado',
            self::InProgress => 'En curso',
            self::Done => 'Hecho',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-amber',
            self::Scheduled => 'badge-blue',
            self::InProgress => 'badge-violet',
            self::Done => 'badge-green',
        };
    }
}
