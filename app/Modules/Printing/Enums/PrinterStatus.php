<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

/**
 * El estado que se enseña de una impresora registrada. Se actualiza al usarla —al conectar, al
 * imprimir, al fallar—, no en vivo: una página no sondea hardware en segundo plano.
 */
enum PrinterStatus: string
{
    case Disponible = 'disponible';
    case Conectada = 'conectada';
    case Desconectada = 'desconectada';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Disponible => 'Disponible',
            self::Conectada => 'Conectada',
            self::Desconectada => 'Desconectada',
            self::Error => 'Error',
        };
    }

    /** La clase `badge-*` de bmos-badge que le corresponde. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Disponible => 'badge-blue',
            self::Conectada => 'badge-green',
            self::Desconectada => 'badge-gray',
            self::Error => 'badge-red',
        };
    }
}
