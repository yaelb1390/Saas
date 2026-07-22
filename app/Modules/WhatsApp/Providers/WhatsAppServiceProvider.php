<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Gateways\EvolutionGateway;
use App\Modules\WhatsApp\Gateways\LogWhatsAppGateway;
use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use App\Modules\WhatsApp\Gateways\WhatsAppGateway;
use Illuminate\Support\ServiceProvider;

final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Usa Evolution API si está configurado; si no, un gateway de log (sin envío real).
        $this->app->bind(WhatsAppGateway::class, fn (): WhatsAppGateway => $this->makeGateway());

        // El mismo proveedor resuelve la gestión de la línea (estado y emparejamiento por QR).
        $this->app->bind(WhatsAppConnection::class, fn (): WhatsAppConnection => $this->makeGateway());
    }

    public function boot(): void
    {
        //
    }

    private function makeGateway(): EvolutionGateway|LogWhatsAppGateway
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('evolution');

        return ! empty($config['base_url'])
            ? new EvolutionGateway($config)
            : new LogWhatsAppGateway;
    }
}
