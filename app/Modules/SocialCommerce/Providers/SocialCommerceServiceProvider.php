<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Arranque del módulo Social Commerce.
 *
 * Vacío a propósito en esta entrega (Fase 3: solo base de datos y modelos). No hay gateway que
 * resolver como en WhatsApp —Social Commerce siempre habla con Zernio, no elige entre proveedores—
 * ni eventos que escuchar todavía: `LeadCaptured` no tiene consumidor interno en v1 (ver
 * SOCIAL_COMMERCE_ARCHITECTURE.md, sección 3). Las fases siguientes añadirán aquí el registro de
 * rutas, el listener del webhook y los jobs de sincronización.
 */
final class SocialCommerceServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
