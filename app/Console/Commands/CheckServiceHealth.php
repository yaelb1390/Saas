<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Monitoring\Health\HealthCheckRunner;
use App\Modules\Core\Monitoring\Health\HealthRegistry;
use App\Modules\Core\Monitoring\Health\HealthStore;
use App\Modules\Core\Support\DbTable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba los servicios externos y guarda lo que respondan.
 *
 * SIN `--presupuesto`, las comprueba TODAS. Con él —que es como lo llama el cron en producción, con
 * un presupuesto por debajo del tope de la función de Vercel (~10 s)— las ordena por LA QUE LLEVA MÁS
 * TIEMPO SIN COMPROBARSE primero, y para en cuanto se acaba el tiempo: si un despliegue serverless
 * corta a mitad, lo que queda sin comprobar es lo que menos urgía —ya se sabía algo reciente de
 * ello—, no un sorteo de qué servicio se quedó sin su turno.
 *
 * `php artisan salud:comprobar --servicio=polar --forzar` para probar una sonda sin esperar el
 * intervalo mínimo de 30 s.
 */
final class CheckServiceHealth extends Command
{
    protected $signature = 'salud:comprobar
                            {--servicio= : Solo esta sonda, por su clave (database, evolution…)}
                            {--forzar : Ignora el intervalo mínimo de 30 s entre comprobaciones}
                            {--presupuesto= : Segundos como mucho; ordena por la más antigua primero}';

    protected $description = 'Comprueba los servicios externos (base de datos, Evolution, IA, Polar, correo…) y guarda su estado.';

    public function handle(HealthRegistry $registro, HealthCheckRunner $runner): int
    {
        if (! DbTable::existe('health_checks')) {
            $this->warn('Falta la migración de la Fase 3: nada que comprobar todavía.');

            return self::SUCCESS;
        }

        $servicio = $this->option('servicio');
        $forzar = (bool) $this->option('forzar');
        $presupuesto = $this->option('presupuesto') !== null ? (int) $this->option('presupuesto') : null;

        $claves = $servicio !== null ? [(string) $servicio] : $this->porAntiguedad($registro->claves());

        $inicio = microtime(true);
        $comprobadas = 0;

        foreach ($claves as $clave) {
            if ($presupuesto !== null && (microtime(true) - $inicio) >= $presupuesto) {
                $this->warn("Presupuesto de {$presupuesto}s agotado: quedan sin comprobar ".(count($claves) - $comprobadas).' sondas.');

                break;
            }

            $resultados = $runner->comprobar($clave, $forzar, HealthStore::TRIGGER_CRON);
            $resultado = $resultados->get($clave);

            if ($resultado === null) {
                $this->line("{$clave}: sin comprobar (dentro del intervalo mínimo).");

                continue;
            }

            $comprobadas++;
            $this->line("{$clave}: {$resultado->status}".($resultado->message !== null ? " ({$resultado->message})" : ''));
        }

        $this->info("{$comprobadas} sonda(s) comprobada(s).");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $claves
     * @return list<string>
     */
    private function porAntiguedad(array $claves): array
    {
        $ultimas = DB::table('health_checks')
            ->whereIn('service', $claves)
            ->pluck('last_checked_at', 'service');

        usort($claves, function (string $a, string $b) use ($ultimas): int {
            // Sin ninguna comprobación previa, va primero: es la más urgente de todas.
            $fechaA = $ultimas->get($a);
            $fechaB = $ultimas->get($b);

            return match (true) {
                $fechaA === null && $fechaB === null => 0,
                $fechaA === null => -1,
                $fechaB === null => 1,
                default => strcmp((string) $fechaA, (string) $fechaB),
            };
        });

        return $claves;
    }
}
