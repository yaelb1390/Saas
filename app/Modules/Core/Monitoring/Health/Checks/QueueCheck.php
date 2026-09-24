<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Monitoring\Queues\QueueMonitor;

/**
 * ¿La cola se está vaciando, o se está acumulando? Dos señales: CUÁNTOS trabajos esperan, y desde
 * CUÁNTO tiempo espera el más viejo —una cola corta con un trabajo atascado desde hace una hora es
 * tan mala señal como una cola larga—.
 *
 * Con `sync` no hay cola propia que comprobar (cada trabajo corre dentro de su petición y ya terminó
 * cuando esto se ejecuta): «no aplica», igual que Redis sin nadie que lo use. El proxy de mensajes de
 * WhatsApp atascados que sí calcula `QueueMonitor` en ese caso es informativo para la tarjeta de la
 * pantalla, no una comprobación de salud —no hay «servicio» que responda mal o bien—.
 */
final class QueueCheck implements HealthCheck
{
    public function __construct(private readonly QueueMonitor $monitor) {}

    public function key(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return 'Colas y trabajos';
    }

    public function run(): HealthResult
    {
        $snapshot = $this->monitor->snapshot();

        if (! $snapshot['aplica']) {
            return HealthResult::sinConfigurar("Sin cola propia (driver «{$snapshot['driver']}»)");
        }

        $config = config('bmos.monitoreo.colas');

        $pendientes = $snapshot['pendientes'];
        $antiguedadMin = $snapshot['antiguedad_segundos'] !== null ? intdiv($snapshot['antiguedad_segundos'], 60) : 0;

        $grave = $pendientes >= (int) $config['pendientes_grave'] || $antiguedadMin >= (int) $config['antiguedad_grave_minutos'];
        $aviso = $pendientes >= (int) $config['pendientes_aviso'] || $antiguedadMin >= (int) $config['antiguedad_aviso_minutos'];

        $detalles = ['pendientes' => $pendientes, 'reservados' => $snapshot['reservados'], 'retrasados' => $snapshot['retrasados']];

        if ($grave) {
            return HealthResult::caido("{$pendientes} pendientes, el más viejo lleva {$antiguedadMin} min");
        }

        if ($aviso) {
            return HealthResult::degradado(null, "{$pendientes} pendientes, el más viejo lleva {$antiguedadMin} min", $detalles);
        }

        return HealthResult::sano(0, "{$pendientes} pendientes", $detalles);
    }
}
