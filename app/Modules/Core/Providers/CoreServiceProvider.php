<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Models\User;
use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Core\Listeners\ProvisionCompanyRoles;
use App\Modules\Core\Repositories\Contracts\CompanyRepositoryInterface;
use App\Modules\Core\Repositories\EloquentCompanyRepository;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\Core\Support\TaxCalculator;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Provider del módulo Core. Registra el contexto de tenant y los bindings de repositorios.
 * Cada módulo del sistema tendrá su propio provider siguiendo este patrón.
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El contexto de empresa vive durante toda la petición.
        $this->app->singleton(CurrentCompany::class);

        $this->app->bind(
            CompanyRepositoryInterface::class,
            EloquentCompanyRepository::class,
        );

        // El cálculo del ITBIS se resuelve desde la configuración fiscal (config/billing.php).
        $this->app->bind(TaxCalculator::class, fn (): TaxCalculator => TaxCalculator::fromConfig());
    }

    public function boot(): void
    {
        Event::listen(CompanyCreated::class, ProvisionCompanyRoles::class);

        // El super administrador opera por encima de los roles de empresa: pasa toda comprobación
        // de permisos. Devolver null (y no false) deja que el resto de reglas decidan al usuario
        // normal; devolver false aquí bloquearía incluso a quien sí tiene el permiso.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);

        // Aviso de vencimiento de la suscripción/prueba, calculado una sola vez y compartido con el
        // parcial que pinta el banner y la ventana emergente. El super admin no recibe avisos (él
        // gestiona los planes); las empresas sin suscripción tampoco (acceso heredado).
        View::composer('partials.subscription-notice', function (ViewContract $view): void {
            $notice = null;
            $user = auth()->user();

            if ($user instanceof User && ! $user->isSuperAdmin()) {
                // Instancia compartida de la petición: no vuelve a consultar la empresa.
                $company = app(CurrentCompany::class)->model();

                $notice = SubscriptionNotice::for($company?->subscription);
            }

            $view->with('subscriptionNotice', $notice);
        });
    }
}
