<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\ElectronicInvoicing\Xml\SchemaRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Arranque del módulo de Facturación Electrónica (e-CF).
 *
 * Fase 0: solo cimientos (configuración, esquemas oficiales, ajustes por empresa y la pantalla de
 * resumen). Los proveedores (PSFE, DGII directo, de prueba) se registrarán aquí en la fase 4,
 * elegidos por empresa, igual que el gateway de WhatsApp.
 */
final class ElectronicInvoicingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lee el manifiesto una vez por petición.
        $this->app->singleton(SchemaRegistry::class);
    }

    public function boot(): void {}
}
