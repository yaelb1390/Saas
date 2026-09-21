<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Errors;

use App\Modules\Core\Support\DbTable;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a las tablas de desglose lo que los grupos de errores anteriores sabían de su empresa y su usuario.
 *
 * Lo llama la migración `backfill_error_event_companies`, y está en una clase (no dentro de la migración)
 * para poder probarla: un backfill que no se prueba es la forma más cara de descubrir un error, porque
 * corre una vez, en producción, a mano.
 *
 * Solo INSERTA en las tablas hijas y ACTUALIZA columnas nuevas (`companies_count`, `users_count`,
 * `service`). No toca `hits`, `message`, `fingerprint` ni ninguna columna que ya existiera. Es idempotente:
 * puede correr dos veces sin duplicar filas ni contar dos veces.
 *
 * Es una aproximación y hay que decirlo: el agrupado de antes pisaba `company_id` en cada repetición, así
 * que un grupo antiguo solo sabe de UNA empresa (la última que lo vio) y se le atribuyen todas sus veces.
 * Por eso la pantalla marca esos grupos como «histórico».
 */
final class ErrorGroupBackfill
{
    /**
     * @return array{companies: int, users: int, services: int} cuántas filas creó o rellenó
     */
    public static function run(): array
    {
        $resultado = ['companies' => 0, 'users' => 0, 'services' => 0];

        if (! DbTable::existe('error_events')
            || ! DbTable::existe('error_event_companies')
            || ! DbTable::existe('error_event_users')) {
            return $resultado;
        }

        DB::table('error_events')->whereNotNull('company_id')->chunkById(500, function ($grupos) use (&$resultado): void {
            foreach ($grupos as $grupo) {
                $existe = DB::table('error_event_companies')
                    ->where('error_event_id', $grupo->id)
                    ->where('company_id', $grupo->company_id)
                    ->exists();

                if ($existe) {
                    continue;
                }

                DB::table('error_event_companies')->insert([
                    'error_event_id' => $grupo->id,
                    'company_id' => $grupo->company_id,
                    'hits' => $grupo->hits,
                    'first_seen_at' => $grupo->first_seen_at,
                    'last_seen_at' => $grupo->last_seen_at,
                ]);

                $resultado['companies']++;
            }
        });

        DB::table('error_events')->whereNotNull('user_id')->chunkById(500, function ($grupos) use (&$resultado): void {
            foreach ($grupos as $grupo) {
                $existe = DB::table('error_event_users')
                    ->where('error_event_id', $grupo->id)
                    ->where('user_id', $grupo->user_id)
                    ->exists();

                if ($existe) {
                    continue;
                }

                DB::table('error_event_users')->insert([
                    'error_event_id' => $grupo->id,
                    'user_id' => $grupo->user_id,
                    'company_id' => $grupo->company_id,
                    'hits' => $grupo->hits,
                    'first_seen_at' => $grupo->first_seen_at,
                    'last_seen_at' => $grupo->last_seen_at,
                ]);

                $resultado['users']++;
            }
        });

        // Los contadores se recalculan desde las filas (no se suman a lo que hubiera): así el resultado es el
        // mismo la segunda vez. Con subconsultas, que funcionan igual en PostgreSQL y en SQLite.
        DB::statement(
            'UPDATE error_events SET '
            .'companies_count = (SELECT COUNT(*) FROM error_event_companies c WHERE c.error_event_id = error_events.id), '
            .'users_count = (SELECT COUNT(*) FROM error_event_users u WHERE u.error_event_id = error_events.id)'
        );

        // El servicio, de los grupos que aún no lo tienen: solo con lo que hay guardado (la clase y la
        // dirección del mensaje; el origen antiguo era solo un nombre de fichero, sin ruta).
        DB::table('error_events')->whereNull('service')->chunkById(500, function ($grupos) use (&$resultado): void {
            foreach ($grupos as $grupo) {
                $servicio = ServiceResolver::forParts(
                    (string) $grupo->class,
                    ServiceResolver::hostFromMessage((string) $grupo->message),
                    null,
                );

                DB::table('error_events')->where('id', $grupo->id)->update(['service' => $servicio]);

                $resultado['services']++;
            }
        });

        return $resultado;
    }
}
