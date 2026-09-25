<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Enums;

/**
 * Estado local de una regla, independiente de `isActive` en Zernio.
 *
 * Existen los dos porque responden preguntas distintas: `isActive` es lo que Zernio hará al
 * próximo comentario; esto es lo que el comerciante ve en su panel, incluyendo estados que Zernio
 * no tiene, como «todavía no se ha sincronizado» o «la última sincronización falló».
 */
enum RuleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Sin publicar',
            self::Active => 'Activa',
            self::Paused => 'Pausada',
            self::Error => 'Con error',
        };
    }
}
