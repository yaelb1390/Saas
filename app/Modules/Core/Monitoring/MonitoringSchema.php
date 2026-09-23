<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring;

use App\Modules\Core\Monitoring\Errors\ErrorRecorder;
use App\Modules\Core\Support\DbTable;

/**
 * ¿Qué de lo nuevo del monitoreo existe ya en esta base de datos?
 *
 * Aquí las migraciones se aplican a mano y el despliegue no las corre: el código llega antes que las
 * tablas y columnas nuevas. La pantalla de monitoreo es a donde se va cuando algo va mal, así que es la
 * última que puede caerse por una migración pendiente. Cada consulta y cada control nuevo pregunta aquí
 * antes de usar lo que todavía puede no existir, y si no está, la pantalla se pinta con lo de siempre.
 *
 * Las respuestas salen de `DbTable`, que las memoriza por proceso: preguntar al catálogo en cada
 * petición sería pagar una consulta por un estado que solo cambia el día que se migra.
 */
final class MonitoringSchema
{
    /**
     * ¿Se aplicó la migración de los errores por empresa? Con ella hay estado (activo, resuelto,
     * ignorado), servicio, y el desglose por empresa y por usuario.
     */
    public static function erroresConDesglose(): bool
    {
        return app(ErrorRecorder::class)->enModoV2();
    }

    /** ¿Tiene ya el registro del sistema la columna del servicio? */
    public static function sucesosConServicio(): bool
    {
        return DbTable::tieneColumna('system_events', 'service');
    }

    /** ¿Existe la tabla de los errores agrupados? */
    public static function hayErrores(): bool
    {
        return DbTable::existe('error_events');
    }

    /** ¿Existe el registro del sistema? */
    public static function haySucesos(): bool
    {
        return DbTable::existe('system_events');
    }
}
