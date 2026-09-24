<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

/**
 * Una sonda: sabe comprobar UN servicio y nada más.
 *
 * `run()` NUNCA debe lanzar hacia fuera —`HealthCheckRunner` la envuelve en `try/catch` igualmente,
 * por si acaso, pero cada sonda ya atrapa lo suyo para poder distinguir "no configurado" de "caído"
 * en vez de que todo se vea igual de mal—.
 */
interface HealthCheck
{
    /** La clave corta: `database`, `evolution`… La misma que `service` en `health_checks`. */
    public function key(): string;

    /** Cómo se llama en la pantalla. */
    public function label(): string;

    public function run(): HealthResult;
}
