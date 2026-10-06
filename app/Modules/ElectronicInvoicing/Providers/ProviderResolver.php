<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;

/**
 * Elige el proveedor de UNA empresa según su configuración. Nunca lanza al elegir: un valor
 * desconocido cae en el PSFE «no configurado», que lo dice con claridad.
 */
final class ProviderResolver
{
    public function for(ElectronicInvoicingSettings $settings): ElectronicInvoiceProvider
    {
        return match ($settings->provider) {
            'dgii' => app(DgiiDirectProvider::class),
            'fake' => app(FakeProvider::class),
            default => app(PsfeProvider::class),
        };
    }

    /**
     * El proveedor para un documento YA enviado: con varios proveedores certificados, el que lo
     * recibió (`psfe:{conector}` en `electronic_invoices.provider`). Las consultas de su estado
     * tienen que ir a él, nunca a otro.
     */
    public function forDocument(ElectronicInvoicingSettings $settings, ?string $documentProvider): ElectronicInvoiceProvider
    {
        $proveedor = $this->for($settings);

        if ($proveedor instanceof PsfeProvider && $documentProvider !== null && str_starts_with($documentProvider, 'psfe:')) {
            return $proveedor->pinned(substr($documentProvider, 5));
        }

        return $proveedor;
    }
}
