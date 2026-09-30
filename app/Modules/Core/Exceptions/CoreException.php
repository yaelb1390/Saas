<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use DomainException;

/**
 * Errores de negocio del módulo Core. Los controladores los capturan y los convierten en un
 * mensaje `panel_error` para el usuario, sin abortar con un 500.
 */
final class CoreException extends DomainException
{
    public static function maxUsersReached(int $limite, string $plan): self
    {
        return new self("Tu plan «{$plan}» permite hasta {$limite} usuario(s). Para agregar otro, cambia a un plan con más cupo.");
    }
}
