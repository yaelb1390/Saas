<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use DOMDocument;
use SensitiveParameter;

/**
 * El conector de UN proveedor certificado (PSFE). Cada proveedor tiene su propia API: este contrato
 * es lo que su mapeo tiene que cumplir, escrito a partir de SU documentación, nunca supuesto.
 *
 * Añadir un proveedor = una clase que implemente esto + una línea en `config/ecf_psfe.php`. La
 * pantalla «Conecta tu proveedor», la validación y el guardado cifrado salen de aquí solos.
 *
 * Todos los métodos reciben las credenciales ya descifradas de la empresa: el conector no sabe
 * dónde se guardan ni las guarda él.
 */
interface PsfeDriver
{
    /** Identificador estable que se guarda en la configuración de la empresa. */
    public function slug(): string;

    public function label(): string;

    /** Una línea para la tarjeta del catálogo. */
    public function description(): string;

    /** @return list<PsfeField> */
    public function fields(): array;

    public function capabilities(): PsfeCapabilities;

    /** Si se puede usar en este ambiente (un conector simulado nunca en producción). */
    public function availableIn(Environment $env): bool;

    /** @param  array<string, string>  $credentials */
    public function testConnection(#[SensitiveParameter] array $credentials, Environment $env): ConnectionCheck;

    /**
     * Firma en el proveedor. Solo se llama si `capabilities()->signs`.
     *
     * @param  array<string, string>  $credentials
     */
    public function sign(Company $company, #[SensitiveParameter] array $credentials, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate): SignedXml;

    /** @param  array<string, string>  $credentials */
    public function send(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** @param  array<string, string>  $credentials */
    public function sendSummary(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** @param  array<string, string>  $credentials */
    public function queryResult(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $trackId): ProviderResult;

    /** @param  array<string, string>  $credentials */
    public function sendCommercialApproval(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** @param  array<string, string>  $credentials */
    public function voidRange(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult;

    /** @param  array<string, string>  $credentials */
    public function findReceiver(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $taxId): ReceiverLookup;
}
