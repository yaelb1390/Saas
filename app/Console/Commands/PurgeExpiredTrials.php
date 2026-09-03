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
    protected $signature = 'trials:purge
                            {--simular : Dice a quién le borraría los datos, sin borrar nada}';

    protected $description = 'Borra los datos de las pruebas vencidas hace más de 24 h (conservando la cuenta).';

    public function handle(TenantDataPurger $purger): int
    {
        $simular = (bool) $this->option('simular');

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
                if (! $simular) {
                    $subscription->update(['purge_at' => null]);
                }

                continue;
            }

            $count++;

            /*
             * El simulacro NO borra Y NO desmarca.
             *
             * Lo segundo importa tanto como lo primero: dejar `purge_at` en NULL después de mirar haría
             * que la purga de verdad se saltara justo a quien acabas de mirar, y esa prueba se
             * quedaría con sus datos para siempre sin que nadie lo notara.
             */
            if ($simular) {
                $vencio = $subscription->purge_at?->format('d/m/Y') ?? '?';
                $this->line("Se borrarían los datos de «{$company->name}» (#{$company->id}), marcada el {$vencio}.");

                continue;
            }

            $purger->purge($company);
            $subscription->update(['purge_at' => null]);

            $this->line("Purgados los datos de prueba de «{$company->name}» (#{$company->id}).");
        }

        $this->info($simular
            ? "Se purgarían {$count} pruebas."
            : "Pruebas purgadas: {$count}.");

        return self::SUCCESS;
    }
}
