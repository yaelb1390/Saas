<?php

declare(strict_types=1);

namespace App\Modules\Core\Providers;

use App\Models\User;
use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Core\Events\SubscriptionCancellationRequested;
use App\Modules\Core\Events\SubscriptionEnded;
use App\Modules\Core\Events\SubscriptionPaymentFailed;
use App\Modules\Core\Events\SubscriptionResumed;
use App\Modules\Core\Listeners\ProvisionCompanyRoles;
use App\Modules\Core\Listeners\RecordAuthEvents;
use App\Modules\Core\Listeners\SendSubscriptionCancelledEmail;
use App\Modules\Core\Listeners\SendSubscriptionEndedEmail;
use App\Modules\Core\Listeners\SendSubscriptionPaymentFailedEmail;
use App\Modules\Core\Listeners\SendSubscriptionResumedEmail;
use App\Modules\Core\Monitoring\Errors\ErrorRecorder;
use App\Modules\Core\Monitoring\Health\HealthRegistry;
use App\Modules\Core\Monitoring\Metrics\DatabaseSink;
use App\Modules\Core\Monitoring\Metrics\MetricsSink;
use App\Modules\Core\Monitoring\Queries\QueryWatcher;
use App\Modules\Core\Monitoring\Queues\QueueEventSubscriber;
use App\Modules\Core\Repositories\Contracts\CompanyRepositoryInterface;
use App\Modules\Core\Repositories\EloquentCompanyRepository;
use App\Modules\Core\Support\SubscriptionNotice;
use App\Modules\Core\Support\TaxCalculator;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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

        // Lleva la cuenta de cuántos errores ha anotado este proceso (su tope por minuto): tiene que ser
        // la misma instancia durante toda la petición o el tope no cuenta nada.
        $this->app->singleton(ErrorRecorder::class);

        // Fase 6: acumula la aritmética de las consultas durante TODA la petición o el trabajo, para
        // escribir una sola vez al terminar. Singleton por el mismo motivo que `ErrorRecorder`.
        $this->app->singleton(QueryWatcher::class);

        $this->app->bind(
            CompanyRepositoryInterface::class,
            EloquentCompanyRepository::class,
        );

        // El cálculo del ITBIS se resuelve desde la configuración fiscal (config/billing.php).
        $this->app->bind(TaxCalculator::class, fn (): TaxCalculator => TaxCalculator::fromConfig());

        // Las sondas de salud (Fase 3), resueltas por el contenedor y no con un `new` a pelo: así
        // `PolarCheck` recibe su `PolarClient` como cualquier otra dependencia.
        $this->app->singleton(HealthRegistry::class, fn (): HealthRegistry => HealthRegistry::porOmision());

        // A dónde van las métricas (Fase 4). Un solo binding: cambiar de sitio de guardado el día
        // que haya Redis en producción es cambiar esta línea, no cada punto que mide algo.
        $this->app->bind(MetricsSink::class, DatabaseSink::class);
    }

    public function boot(): void
    {
        Event::listen(CompanyCreated::class, ProvisionCompanyRoles::class);

        // La baja de una suscripción y su reversión: el correo al cliente, en español y con nuestra
        // marca en vez del aviso genérico de Polar. Los mismos eventos son el punto de enganche para
        // n8n. Salen del cambio de estado (`SubscriptionService`), no de la puerta por la que entró.
        Event::listen(SubscriptionCancellationRequested::class, SendSubscriptionCancelledEmail::class);
        Event::listen(SubscriptionResumed::class, SendSubscriptionResumedEmail::class);

        // El cobro que falla y el fin de la suscripción: lo que antes solo avisaba Polar, en inglés.
        Event::listen(SubscriptionPaymentFailed::class, SendSubscriptionPaymentFailedEmail::class);
        Event::listen(SubscriptionEnded::class, SendSubscriptionEndedEmail::class);

        /*
         * Quién entra, quién sale y quién lo intenta sin conseguirlo.
         *
         * Se suscribe a los eventos que Laravel ya dispara en vez de tocar el controlador de acceso:
         * así queda cubierto TODO —el formulario, Google, las passkeys, el segundo factor— sin tener
         * que acordarse de cada puerta cada vez que se añade una.
         */
        Event::subscribe(RecordAuthEvents::class);

        /*
         * Cada trabajo de cola, cuánto tardó y si falló (Fase 4).
         *
         * `createPayloadUsing` mete la empresa activa EN EL PROPIO PAYLOAD al despachar el trabajo,
         * dentro de la petición que lo encola y con `CurrentCompany` todavía en su sitio. Es lo
         * único que le permite a `QueueEventSubscriber` —que corre después, quizás en otro proceso,
         * quizás con la cola ya vacía de tenant— saber de qué empresa era sin tocar `CurrentCompany`.
         */
        // `$queue` llega `null` cuando se despacha sin nombrar una cola explícita (el caso normal
        // aquí, que no usa colas nombradas): el contrato real de Laravel lo admite así, aunque su
        // propio docblock diga `string`.
        Queue::createPayloadUsing(fn (?string $connection, ?string $queue, array $payload): array => [
            'bmos_company_id' => app(CurrentCompany::class)->id(),
        ]);
        Event::subscribe(QueueEventSubscriber::class);

        /*
         * Fase 6: un único `DB::listen` para TODA la aplicación. Se registra siempre —es solo una
         * suscripción, no un trabajo— y es el CIERRE, no `QueryWatcher` directamente, el que
         * comprueba el interruptor en cada consulta: así un test puede encenderlo con `config()`
         * a mitad de sesión y que surta efecto, igual que hace `RecordRequestMetrics` con el suyo.
         */
        DB::listen(fn (QueryExecuted $evento) => app(QueryWatcher::class)->observar($evento));

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
