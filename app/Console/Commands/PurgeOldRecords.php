<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Support\DbTable;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Poda las dos tablas que crecen solas: la auditoría y el registro de sucesos.
 *
 * POR QUÉ EXISTE. Al medir la base de datos aparecieron de primeras y de lejos, con el sistema
 * prácticamente vacío: `audits` la mayor y `system_events` la segunda, con 62 y 53 filas entre las
 * dos. No crecen con las ventas: crecen con CADA cambio de CUALQUIERA de los modelos auditados y con
 * cada acción digna de registrar. Un negocio de verdad escribe ahí miles de filas al día, y hasta hoy
 * NADA las borraba nunca —lo único que las tocaba era el borrado de una empresa entera—.
 *
 * LAS DOS NO VALEN LO MISMO, y por eso no se tratan igual:
 *
 * - `system_events` es NUESTRO diario de a bordo: que si el cron corrió, que si un aviso falló. Pasado
 *   un tiempo no le sirve a nadie, así que se poda de fábrica a los 90 días.
 *
 * - `audits` es el rastro del NEGOCIO: quién cambió este precio, quién borró aquel cliente. Eso se
 *   consulta cuando hay una discusión, y a veces meses después. Borrarlo por nuestra cuenta sería
 *   decidir por el dueño, así que viene APAGADO (cero días) y hay que encenderlo a propósito.
 *
 * Es la misma regla que en `PurgeOldConversations`: cero no borra nada, y lo que se borra solo es lo
 * que nadie echará de menos.
 *
 * A mano: `php artisan registros:purgar --simular` para ver qué se llevaría sin llevárselo.
 */
final class PurgeOldRecords extends Command
{
    protected $signature = 'registros:purgar
                            {--auditoria= : Fuerza los días de la auditoría, ignorando la configuración}
                            {--sucesos= : Fuerza los días del registro de sucesos}
                            {--simular : Cuenta lo que borraría sin borrar nada}';

    protected $description = 'Poda la auditoría y el registro de sucesos más viejos que su retención.';

    /**
     * Cuántas filas se borran de una tacada.
     *
     * No es un `delete` a secas a propósito. Un borrado de medio millón de filas mantiene el bloqueo
     * hasta que termina, y mientras tanto cualquiera que guarde algo se queda esperando. En trozos, el
     * bloqueo dura lo que dura cada trozo y la aplicación sigue respondiendo.
     */
    private const LOTE = 1000;

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');

        $total = $this->podar(
            tabla: 'system_events',
            dias: $this->dias('sucesos', 'bmos.retencion.sucesos', 90),
            titulo: 'Sucesos del sistema',
            simular: $simular,
        );

        $total += $this->podar(
            tabla: 'audits',
            dias: $this->dias('auditoria', 'bmos.retencion.auditoria', 0),
            titulo: 'Auditoría',
            simular: $simular,
        );

        $this->info($simular
            ? "Se borrarían {$total} filas."
            : "Filas borradas: {$total}.");

        return self::SUCCESS;
    }

    /** Los días a aplicar: manda la opción de la línea de comandos, si no la configuración. */
    private function dias(string $opcion, string $clave, int $porOmision): int
    {
        $forzado = $this->option($opcion);

        return $forzado !== null ? (int) $forzado : (int) config($clave, $porOmision);
    }

    private function podar(string $tabla, int $dias, string $titulo, bool $simular): int
    {
        /*
         * Cero apaga la poda, y el `<= 0` cubre además un negativo que se hubiera colado: restarle
         * días negativos a hoy da un corte EN EL FUTURO, y eso no podaría lo viejo, se llevaría la
         * tabla entera incluida la fila escrita hace un minuto.
         */
        if ($dias <= 0) {
            $this->line("{$titulo}: retención apagada, no se toca.");

            return 0;
        }

        // Las migraciones aquí se aplican a mano; entre que sale este comando y alguien migra, la
        // tabla puede no estar. Se sale sin ruido en vez de tumbar el cron.
        if (! DbTable::existe($tabla)) {
            $this->warn("{$titulo}: la tabla {$tabla} todavía no existe.");

            return 0;
        }

        $corte = CarbonImmutable::now()->subDays($dias);

        if ($simular) {
            $cuantos = DB::table($tabla)->where('created_at', '<', $corte)->count();
            $this->line("{$titulo}: {$cuantos} filas de más de {$dias} días.");

            return $cuantos;
        }

        $borradas = 0;

        /*
         * En trozos y por clave primaria. PostgreSQL no admite `DELETE ... LIMIT`, así que primero se
         * piden los identificadores del lote y luego se borran esos. Da una vuelta más a la base pero
         * es lo que mantiene corto el bloqueo.
         */
        do {
            $ids = DB::table($tabla)
                ->where('created_at', '<', $corte)
                ->orderBy('id')
                ->limit(self::LOTE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $borradas += DB::table($tabla)->whereIn('id', $ids)->delete();
        } while (count($ids) === self::LOTE);

        if ($borradas > 0) {
            $this->line("{$titulo}: {$borradas} filas de más de {$dias} días, borradas.");
        }

        return $borradas;
    }
}
