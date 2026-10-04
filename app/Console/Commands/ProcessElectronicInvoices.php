<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Support\DbTable;
use App\Modules\ElectronicInvoicing\Application\ElectronicInvoiceService;
use Illuminate\Console\Command;

/**
 * Envía los e-CF que quedaron pendientes (fallo de comunicación, contingencia) y consulta el resultado
 * de los que la DGII ya recibió con TrackId.
 *
 * Lo dispara el llamador externo cada 5 minutos (cron-job.org → /tareas/ecf-procesar); también a mano:
 * `php artisan ecf:procesar-pendientes --presupuesto=8`.
 */
final class ProcessElectronicInvoices extends Command
{
    protected $signature = 'ecf:procesar-pendientes {--presupuesto=8 : Segundos máximos de trabajo (la función de Vercel corta en ~10 s)}';

    protected $description = 'Envía los e-CF pendientes y consulta el resultado de los recibidos por la DGII.';

    public function handle(ElectronicInvoiceService $service): int
    {
        // El código llega a producción antes que la migración (se aplica a mano): sin tabla no hay nada que hacer.
        if (! DbTable::existe('electronic_invoices')) {
            $this->info('Facturación electrónica sin migrar: nada que procesar.');

            return self::SUCCESS;
        }

        $r = $service->processPending(max(1, (int) $this->option('presupuesto')));

        $this->info("e-CF enviados: {$r['enviados']}. Consultados: {$r['consultados']}. Entregados al comprador: {$r['entregados']}. Quedan para la próxima: {$r['restantes']}.");

        return self::SUCCESS;
    }
}
