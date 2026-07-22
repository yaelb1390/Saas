<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\CustomerPortalController;
use App\Modules\Billing\Events\InvoiceCancelled;
use App\Modules\Billing\Events\InvoiceIssued;
use App\Modules\Core\Cache\CompanyCache;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Sales\Events\SaleCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Invalidación de la caché de lectura del Portal del Cliente.
 *
 * El portal (facturas, ventas y entregas del cliente) se cachea por empresa vía {@see CompanyCache}.
 * Aquí se cablea la invalidación: cada vez que ocurre una mutación que cambia esas listas, se
 * incrementa la versión de caché de la empresa afectada y el portal se recalcula en la siguiente
 * visita. Entre mutaciones, las lecturas repetidas salen de caché sin tocar la base de datos.
 *
 * Vive a nivel de aplicación —no dentro de un módulo— por el mismo motivo que
 * {@see CustomerPortalController}: es una preocupación transversal que
 * escucha eventos de tres dominios (Ventas, Facturación y Entregas). El `company_id` se lee del
 * propio modelo del evento, sin depender del tenant activo de la petición.
 */
final class PortalCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $cache = $this->app->make(CompanyCache::class);

        Event::listen(SaleCompleted::class, static fn (SaleCompleted $e) => $cache->flush((int) $e->sale->company_id));
        Event::listen(InvoiceIssued::class, static fn (InvoiceIssued $e) => $cache->flush((int) $e->invoice->company_id));
        Event::listen(InvoiceCancelled::class, static fn (InvoiceCancelled $e) => $cache->flush((int) $e->invoice->company_id));
        Event::listen(DeliveryStatusChanged::class, static fn (DeliveryStatusChanged $e) => $cache->flush((int) $e->delivery->company_id));
    }
}
