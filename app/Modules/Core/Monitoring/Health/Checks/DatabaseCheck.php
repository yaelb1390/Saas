<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ¿Responde la base de datos? Siempre «configurada»: sin ella la aplicación entera no arrancaría, así
 * que preguntarlo no tiene sentido —al revés que Evolution o la IA, que son de verdad opcionales—.
 *
 * Degradada por umbral de latencia: un `select 1` que tarda medio segundo dice que el pooler de
 * Supabase está bajo presión, aunque técnicamente «responda».
 */
final class DatabaseCheck implements HealthCheck
{
    private const UMBRAL_DEGRADADO_MS = 300;

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

        return $latencia > self::UMBRAL_DEGRADADO_MS
            ? HealthResult::degradado($latencia, "Responde lento: {$latencia} ms")
            : HealthResult::sano($latencia);
    }
}
