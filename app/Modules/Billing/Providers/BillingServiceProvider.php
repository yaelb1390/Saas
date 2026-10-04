<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Contracts\ElectronicInvoicingHook;
use App\Modules\Billing\Contracts\NoElectronicInvoicing;
use App\Modules\Billing\Services\Extraction\InvoiceExtractor;
use App\Modules\Billing\Services\Extraction\NullInvoiceExtractor;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Extracción de facturas: sin IA por ahora (entrada manual). Cuando haya API key, se cambia
        // aquí por una implementación con visión que pre-llena el formulario.
        $this->app->bind(InvoiceExtractor::class, NullInvoiceExtractor::class);

        // Sin el módulo de facturación electrónica, solo serie B. `bindIf`: si ese módulo está
        // registrado, su implementación manda, sea cual sea el orden de los proveedores.
        $this->app->bindIf(ElectronicInvoicingHook::class, NoElectronicInvoicing::class);
    }

    public function boot(): void
    {
        //
    }
}
