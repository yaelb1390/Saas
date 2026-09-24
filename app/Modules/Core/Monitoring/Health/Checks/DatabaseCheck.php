<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Monitoring\Queries\PostgresStats;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ¿Responde la base de datos? Siempre «configurada»: sin ella la aplicación entera no arrancaría, así
 * que preguntarlo no tiene sentido —al revés que Evolution o la IA, que son de verdad opcionales—.
 *
 * Degradada por umbral de latencia (un `select 1` que tarda medio segundo dice que el pooler de
 * Supabase está bajo presión, aunque técnicamente «responda») o por espacio agotado (Fase 6): las
 * estadísticas de `PostgresStats` son de mejor esfuerzo y NUNCA hacen caer la sonda por sí solas —en
 * SQLite, o si cualquiera de sus consultas falla, simplemente no hay detalle que añadir—.
 */
final class DatabaseCheck implements HealthCheck
{
    private const UMBRAL_DEGRADADO_MS = 300;

    public function __construct(private readonly PostgresStats $estadisticas) {}

    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Base de datos';
    }

    public function run(): HealthResult
    {
        $inicio = microtime(true);

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);
        $stats = $this->estadisticas->leer();
        $resumen = $this->resumen($stats);

        $sobreCupo = $stats !== null
            && isset($stats['tamano_mb'], $stats['cupo_mb'])
            && $stats['cupo_mb'] > 0
            && $stats['tamano_mb'] >= $stats['cupo_mb'];

        if ($sobreCupo) {
            return HealthResult::degradado($latencia, $this->conResumen("Al cupo de espacio ({$stats['tamano_mb']}/{$stats['cupo_mb']} MB)", $resumen), $stats ?? []);
        }

        if ($latencia > self::UMBRAL_DEGRADADO_MS) {
            return HealthResult::degradado($latencia, $this->conResumen("Responde lento: {$latencia} ms", $resumen), $stats ?? []);
        }

        return HealthResult::sano($latencia, $resumen !== '' ? $resumen : null, $stats ?? []);
    }

    /** «247/500 conexiones · 812/500 MB»: lo que cabe en la línea de mensaje de la sonda. */
    private function resumen(?array $stats): string
    {
        if ($stats === null) {
            return '';
        }

        $partes = [];

        if (isset($stats['conexiones'], $stats['conexiones_max'])) {
            $partes[] = "{$stats['conexiones']}/{$stats['conexiones_max']} conexiones";
        }

        if (isset($stats['tamano_mb'], $stats['cupo_mb'])) {
            $partes[] = "{$stats['tamano_mb']}/{$stats['cupo_mb']} MB";
        }

        return implode(' · ', $partes);
    }

    private function conResumen(string $mensaje, string $resumen): string
    {
        return $resumen !== '' ? "{$mensaje} · {$resumen}" : $mensaje;
    }
}
