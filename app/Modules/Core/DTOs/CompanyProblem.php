<?php

declare(strict_types=1);

namespace App\Modules\Core\DTOs;

/**
 * UN problema concreto de una empresa, dentro de un dominio (Sistema, Facturación…).
 *
 * `severidad` usa los mismos tres niveles que el resto del monitoreo —ver `HealthStatus`—, pero solo
 * dos hacen falta aquí: `warning` (informa) y `critical` (le impide operar hoy). No hay `healthy`
 * porque un dominio sano no tiene problemas que listar; su ausencia ya lo dice.
 */
final readonly class CompanyProblem
{
    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public function __construct(
        public string $texto,
        public string $severidad,
        /** A qué pestaña o pantalla lleva a resolverlo, si hay una obvia. */
        public ?string $enlace = null,
    ) {}
}
