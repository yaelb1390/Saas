<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Queries;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lo que PostgreSQL sabe de sí mismo, de mejor esfuerzo: conexiones, bloqueos sin conceder, la
 * transacción más larga abierta, deadlocks nuevos desde la última vez y el tamaño contra el cupo.
 *
 * Cada dato en su PROPIO `try/catch`: contra el pooler de sesión de Supabase, una vista del sistema
 * puede no estar accesible sin que las demás fallen, y perder un dato no puede tumbar el resto ni la
 * sonda que los pide.
 *
 * Solo PostgreSQL: en SQLite (los tests) o cualquier otro motor, `leer()` devuelve `null` sin
 * preguntar nada.
 */
final class PostgresStats
{
    private const CACHE_DEADLOCKS = 'bmos:postgres:deadlocks_previos';

    /**
     * @return array<string, int>|null
     */
    public function leer(): ?array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return null;
        }

        $stats = [];

        try {
            $activas = DB::selectOne('select count(*) as n from pg_stat_activity where datname = current_database()');
            $maximas = DB::selectOne("select setting from pg_settings where name = 'max_connections'");
            $stats['conexiones'] = (int) $activas->n;
            $stats['conexiones_max'] = (int) $maximas->setting;
        } catch (Throwable) {
        }

        try {
            $locks = DB::selectOne('select count(*) as n from pg_locks where not granted');
            $stats['locks_sin_conceder'] = (int) $locks->n;
        } catch (Throwable) {
        }

        try {
            $transaccion = DB::selectOne(
                'select coalesce(max(extract(epoch from (now() - xact_start))), 0) as segundos '
                .'from pg_stat_activity where xact_start is not null and pid <> pg_backend_pid()'
            );
            $stats['transaccion_mas_larga_seg'] = (int) round((float) $transaccion->segundos);
        } catch (Throwable) {
        }

        try {
            $fila = DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()');
            $actuales = (int) $fila->deadlocks;
            $anteriores = (int) Cache::get(self::CACHE_DEADLOCKS, $actuales);
            Cache::put(self::CACHE_DEADLOCKS, $actuales, now()->addDay());
            $stats['deadlocks_nuevos'] = max(0, $actuales - $anteriores);
        } catch (Throwable) {
        }

        try {
            $tamano = DB::selectOne('select pg_database_size(current_database()) as bytes');
            $stats['tamano_mb'] = (int) round(((int) $tamano->bytes) / 1024 / 1024);
            $stats['cupo_mb'] = (int) config('bmos.monitoreo.base_datos.cupo_mb', 500);
        } catch (Throwable) {
        }

        return $stats === [] ? null : $stats;
    }
}
