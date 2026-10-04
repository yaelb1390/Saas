<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;

/**
 * Escenario B: un Proveedor de Servicios de Facturación Electrónica certificado por la DGII.
 *
 * AÚN NO HAY PROVEEDOR ELEGIDO. Cada PSFE tiene su propia API (y la DGII no aclara si firma con su
 * certificado o con el del contribuyente), así que no se inventa una: este adaptador responde «no
 * configurado» con un mensaje claro hasta que se elija uno y se escriba su mapeo. El contrato
 * (`ElectronicInvoiceProvider`) ya es el que ese mapeo tendrá que cumplir.
 */
final class PsfeProvider implements ElectronicInvoiceProvider
{
    private const MENSAJE = 'Todavía no se ha elegido un proveedor certificado (PSFE). Mientras tanto, usa el proveedor de prueba o la conexión directa con la DGII.';

    public function name(): string
    {
        return 'psfe';
    }

    public function isConfigured(Company $company): bool
    {
        return false;
    }

    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE);
    }

    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE);
    }

    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE);
    }

    public function sendCommercialApproval(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE);
    }

    public function voidRange(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE);
    }

    public function findReceiver(Company $company, Environment $env, string $taxId): ReceiverLookup
    {
        return new ReceiverLookup(ReceiverLookup::UNSUPPORTED, error: self::MENSAJE);
    }
}
