<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health;

use App\Modules\Core\Monitoring\Incidents\IncidentDetector;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ejecuta las sondas, guarda lo que digan, y avisa si hay que abrir un incidente.
 *
 * Dos cosas evitan que esto se dispare más de lo que debe:
 *
 *  · Un CANDADO por servicio (`Cache::lock`): si el cron, un clic en «Comprobar ahora» y el panel
 *    piden lo mismo a la vez, solo uno de verdad llama al proveedor externo.
 *  · Un INTERVALO MÍNIMO de 30 s (salvo `--forzar`): sin él, alguien pulsando «Comprobar ahora» sin
 *    parar podría convertirse en un ataque contra el propio proveedor —o gastar cuota de la cuenta de
 *    IA— sin que nadie lo quisiera.
 *
 * Una sonda que LANZA cuenta como caída: es la misma regla que el resto del monitoreo (`DbTable`,
 * `SystemEvent::registrar`), una comprobación de más no puede tumbar la pantalla que la aloja.
 */
final class HealthCheckRunner
{
    private const INTERVALO_MINIMO_SEGUNDOS = 30;

    public function __construct(
        private readonly HealthRegistry $registro,
        private readonly HealthStore $store,
        private readonly IncidentDetector $incidentes,
    ) {}

    /**
     * @return Collection<string, HealthResult> lo que de verdad se comprobó (vacío si todo estaba
     *                                           dentro del intervalo mínimo y nadie forzó)
     */
    public function comprobar(?string $servicioUnico = null, bool $forzar = false, string $trigger = HealthStore::TRIGGER_CRON): Collection
    {
        $sondas = $servicioUnico !== null
            ? collect([$this->registro->porClave($servicioUnico)])->filter()
            : $this->registro->todas();

        $resultados = collect();

        foreach ($sondas as $sonda) {
            $resultado = $this->comprobarUna($sonda, $forzar, $trigger);

            if ($resultado !== null) {
                $resultados->put($sonda->key(), $resultado);
            }
        }

        if ($resultados->isNotEmpty()) {
            Cache::forget('platform:health');
        }

        return $resultados;
    }

    private function comprobarUna(HealthCheck $sonda, bool $forzar, string $trigger): ?HealthResult
    {
        $clave = $sonda->key();

        if (! $forzar && ! $this->pasoElIntervalo($clave)) {
            return null;
        }

        $candado = Cache::lock("health:{$clave}", self::INTERVALO_MINIMO_SEGUNDOS);

        if (! $candado->get()) {
            return null; // otro proceso ya está comprobando este servicio ahora mismo
        }

        try {
            $resultado = $this->ejecutarSinLanzar($sonda);
            $conteos = $this->store->guardar($clave, $resultado, $trigger);

            $this->incidentes->evaluarSalud($clave, $sonda->label(), $resultado, $conteos['consecutive_failures']);

            return $resultado;
        } finally {
            $candado->release();
        }
    }

    private function ejecutarSinLanzar(HealthCheck $sonda): HealthResult
    {
        try {
            return $sonda->run();
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }
    }

    private function pasoElIntervalo(string $clave): bool
    {
        $ultima = DB::table('health_checks')->where('service', $clave)->value('last_checked_at');

        return $ultima === null || Carbon::parse($ultima)->addSeconds(self::INTERVALO_MINIMO_SEGUNDOS)->isPast();
    }
}
