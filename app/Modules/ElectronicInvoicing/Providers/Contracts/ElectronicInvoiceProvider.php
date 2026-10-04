<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Contracts;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;

/**
 * Quien hace llegar un e-CF a la DGII: la propia DGII (sistema propio, escenario A), un proveedor
 * certificado (PSFE, escenario B) o el proveedor de prueba.
 *
 * Recibe el XML ya FIRMADO por BMIA (los bytes exactos) y su nombre de archivo oficial
 * (RNC + e-NCF + .xml [DT p.12]). Nadie fuera de esta capa sabe hablar con la DGII: controladores,
 * vistas y el resto de módulos solo hablan con `ElectronicInvoiceService`.
 */
interface ElectronicInvoiceProvider
{
    public function name(): string;

    /** Si está listo para usarse con esta empresa (credenciales, certificado…). */
    public function isConfigured(Company $company): bool;

    /** Envía un e-CF completo. Normalmente responde `Received` con TrackId. */
    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** Envía un resumen de factura de consumo (RFCE): respuesta síncrona, sin TrackId [DT p.15]. */
    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** Consulta el resultado de un envío por su TrackId [DT p.22]. */
    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult;

    /** Envía a la DGII la aprobación comercial (ACECF) que la empresa emite como compradora [DT pp.31–33]. */
    public function sendCommercialApproval(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** Anula un rango de e-NCF no usados (ANECF) [DT pp.34–36]. */
    public function voidRange(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** Busca a un contribuyente en el directorio de receptores electrónicos [DT pp.37–39]. */
    public function findReceiver(Company $company, Environment $env, string $taxId): ReceiverLookup;
}
