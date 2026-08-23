<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Events\WhatsAppMessageReceived;
use App\Modules\WhatsApp\Gateways\EvolutionGateway;
use App\Modules\WhatsApp\Gateways\LogWhatsAppGateway;
use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use App\Modules\WhatsApp\Gateways\ZernioWhatsAppGateway;
use App\Modules\WhatsApp\Listeners\ReplyToCustomer;
use App\Modules\WhatsApp\Models\WaBotSetting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Se resuelve en cada petición y NO como singleton: la vía depende de la empresa activa, y
        // un operador de plataforma cambia de empresa sin recargar el contenedor.
        $this->app->bind(WhatsAppGateway::class, fn (): WhatsAppGateway => $this->makeGateway());

        // El mismo proveedor resuelve la gestión de la línea (estado y vínculo).
        $this->app->bind(WhatsAppConnection::class, fn (): WhatsAppConnection => $this->makeGateway());
    }

    public function boot(): void
    {
        /*
         * Que el bot conteste a los clientes.
         *
         * Se engancha al evento que ya existía —su docblock decía desde el principio que era «punto
         * de enganche para respuestas automáticas»— y convive con el análisis de sentimiento, que
         * escucha lo mismo. Dos oyentes del mismo mensaje: uno lo clasifica y el otro contesta.
         */
        Event::listen(WhatsAppMessageReceived::class, ReplyToCustomer::class);
    }

    /**
     * Por dónde habla WhatsApp esta empresa.
     *
     * El orden importa y es el de la confianza, no el de la comodidad:
     *
     *  1. **La vía oficial** si la empresa la eligió y tiene clave de Zernio. Es la API de Meta: no
     *     hay riesgo de que bloqueen el número y funciona desde producción sin servidor propio.
     *  2. **El emparejamiento por QR** si Evolution está configurado. Rápido y gratis, pero es la
     *     sesión de WhatsApp Web y Meta puede bloquear el número.
     *  3. **El de registro**, que no envía nada. Es lo que evita que una empresa a medio configurar
     *     crea que está mandando mensajes.
     */
    private function makeGateway(): EvolutionGateway|LogWhatsAppGateway|ZernioWhatsAppGateway
    {
        $company = $this->empresaConZernio();

        if ($company !== null) {
            return new ZernioWhatsAppGateway($company);
        }

        /** @var array<string, mixed> $config */
        $config = (array) config('evolution');

        return ! empty($config['base_url'])
            ? new EvolutionGateway($config)
            : new LogWhatsAppGateway;
    }

    /**
     * La empresa activa, si eligió la vía oficial y puede usarla.
     *
     * Nunca lanza. Esto corre al resolver el gateway, así que un fallo aquí —la tabla que todavía no
     * está migrada, la base que no contesta— tumbaría cualquier pantalla que toque WhatsApp. Ante la
     * duda se devuelve null y se cae a la vía de siempre, que es el comportamiento que ya había.
     */
    private function empresaConZernio(): ?Company
    {
        try {
            if (! DbTable::existe('wa_bot_settings')) {
                return null;
            }

            $companyId = app(CurrentCompany::class)->id();

            if ($companyId === null) {
                return null;
            }

            $ajustes = WaBotSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

            if ($ajustes === null || ! $ajustes->usaZernio()) {
                return null;
            }

            $company = Company::query()->find($companyId);

            // Sin clave de Zernio la vía oficial no existe: se cae a la otra en vez de dejar la
            // línea muerta sin decir por qué.
            return $company !== null && filled($company->social_api_key) ? $company : null;
        } catch (Throwable) {
            return null;
        }
    }
}
