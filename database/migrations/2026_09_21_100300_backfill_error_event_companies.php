<?php

declare(strict_types=1);

use App\Modules\Core\Monitoring\Errors\ErrorGroupBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Lleva a las tablas nuevas lo que los errores que ya existen sabían de su empresa y su usuario.
 *
 * Es SOLO datos y va en su propio fichero, aparte del esquema, para poder aplicarse (y comprobarse)
 * por separado en producción, donde las migraciones se corren a mano.
 *
 * Qué hace: por cada grupo con `company_id` o `user_id`, crea la fila de desglose correspondiente con el
 * total de veces del grupo, y recalcula los contadores. Además rellena `service` a partir de la clase del
 * error. NO toca `hits`, `message` ni `fingerprint`, así que no puede estropear lo que ya se guardó.
 *
 * Es aproximado, y se dice: el agrupado de antes pisaba la empresa en cada repetición, así que el
 * desglose de un grupo antiguo es «la última empresa que lo vio con todas las veces». La pantalla lo marca
 * como histórico. Y es idempotente: puede correr dos veces sin duplicar nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        ErrorGroupBackfill::run();
    }

    public function down(): void
    {
        // No hay nada que deshacer: las filas de desglose se van con `error_event_companies` y
        // `error_event_users` (sus propias migraciones), y `service` con la columna.
    }
};
