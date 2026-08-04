<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

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
    }

    public function boot(): void
    {
        //
    }
}
