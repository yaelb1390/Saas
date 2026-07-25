<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\TenantDataPurger;
use Illuminate\Console\Command;

/**
 * Borra los datos de las pruebas self-service que vencieron y no se convirtieron en plan: 24 h
 * después del fin de prueba (`purge_at` <= ahora) y aún en estado `trialing`. Conserva la cuenta;
 * solo limpia los datos de negocio (ver TenantDataPurger). Deja `purge_at` en NULL para no repetir.
 *
 * Lo dispara el cron de Vercel (endpoint /tareas/purgar-pruebas) o el scheduler; también se puede
 * correr a mano: `php artisan trials:purge`.
 */
final class PurgeExpiredTrials extends Command
{
    protected $signature = 'trials:purge';

    protected $description = 'Borra los datos de las pruebas vencidas hace más de 24 h (conservando la cuenta).';

    public function handle(TenantDataPurger $purger): int
    {
        // Sin scope de empresa: recorremos todas las suscripciones marcadas para purga.
        $due = Subscription::query()
            ->with('company')
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now())
            ->where('status', SubscriptionStatus::Trialing)
            ->get();

        $count = 0;

        foreach ($due as $subscription) {
            $company = $subscription->company;

            if ($company === null) {
                // Sin empresa (caso anómalo): solo desmarcamos para no reintentar indefinidamente.
                $subscription->update(['purge_at' => null]);

                continue;
            }

            $purger->purge($company);
            $subscription->update(['purge_at' => null]);
            $count++;

            $this->line("Purgados los datos de prueba de «{$company->name}» (#{$company->id}).");
        }

        $this->info("Pruebas purgadas: {$count}.");

        return self::SUCCESS;
    }
}
