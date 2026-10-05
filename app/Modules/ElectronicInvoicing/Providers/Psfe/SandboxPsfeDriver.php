<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicCertificate;
use App\Modules\ElectronicInvoicing\Providers\FakeProvider;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Signature\LoadedCertificate;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use DOMDocument;
use RuntimeException;
use SensitiveParameter;

/**
 * Proveedor certificado SIMULADO: recorre el circuito de un PSFE que firma (conectar, probar, firmar,
 * enviar, consultar) sin hablar con nadie. Es lo que permite probar la conexión antes de tener el
 * conector real de un proveedor.
 *
 * - Acepta cualquier clave que empiece por `sandbox_`; las demás las rechaza, como haría un
 *   proveedor real con una clave mala.
 * - Firma con un certificado de usar y tirar generado en memoria: el XML sale firmado de verdad
 *   (con su código de seguridad), pero esa firma no la reconoce la DGII.
 * - Las respuestas de envío y consulta son las del proveedor de prueba (`ecf.fake.*`).
 * - Nunca en producción.
 */
final class SandboxPsfeDriver implements PsfeDriver
{
    /** El certificado de usar y tirar, uno por proceso: generarlo cuesta. */
    private static ?LoadedCertificate $certificado = null;

    public function __construct(
        private readonly FakeProvider $respuestas,
        private readonly XmlSigner $signer,
    ) {}

    public function slug(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Proveedor simulado (pruebas)';
    }

    public function description(): string
    {
        return 'Para probar la conexión y la firma por el proveedor sin enviar nada a la DGII. Clave: cualquiera que empiece por «sandbox_».';
    }

    public function fields(): array
    {
        return [
            new PsfeField('api_key', 'Clave de API', secret: true, help: 'En el simulado, cualquier clave que empiece por «sandbox_».'),
        ];
    }

    public function capabilities(): PsfeCapabilities
    {
        return new PsfeCapabilities(signs: true);
    }

    public function availableIn(Environment $env): bool
    {
        return ! $env->isFiscal();
    }

    public function testConnection(#[SensitiveParameter] array $credentials, Environment $env): ConnectionCheck
    {
        if (! $this->availableIn($env)) {
            return new ConnectionCheck(false, 'El proveedor simulado no puede usarse en producción.');
        }

        return str_starts_with((string) ($credentials['api_key'] ?? ''), 'sandbox_')
            ? new ConnectionCheck(true, 'Conexión correcta con el proveedor simulado.', 'Cuenta de pruebas')
            : new ConnectionCheck(false, 'El proveedor rechazó la clave de API.');
    }

    public function sign(Company $company, #[SensitiveParameter] array $credentials, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate): SignedXml
    {
        if (! $this->availableIn($env)) {
            throw new RuntimeException('El proveedor simulado no puede firmar en producción.');
        }

        return $this->signer->sign($unsigned, $this->certificado(), now(), $writeSignatureDate);
    }

    public function send(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->respuestas->send($company, $env, $signedXml, $fileName);
    }

    public function sendSummary(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->respuestas->sendSummary($company, $env, $signedXml, $fileName);
    }

    public function queryResult(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $trackId): ProviderResult
    {
        return $this->respuestas->queryResult($company, $env, $trackId);
    }

    public function sendCommercialApproval(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: 'El proveedor simulado no tramita aprobaciones comerciales.');
    }

    public function voidRange(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: 'El proveedor simulado no tramita anulaciones de rangos.');
    }

    public function findReceiver(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $taxId): ReceiverLookup
    {
        return $this->respuestas->findReceiver($company, $env, $taxId);
    }

    private function certificado(): LoadedCertificate
    {
        if (self::$certificado !== null) {
            return self::$certificado;
        }

        $clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])
            ?: throw new RuntimeException('No se pudo generar la clave del proveedor simulado.');
        $solicitud = openssl_csr_new(['commonName' => 'PSFE simulado BMIA'], $clave, ['digest_alg' => 'sha256'])
            ?: throw new RuntimeException('No se pudo generar el certificado del proveedor simulado.');
        $x509 = openssl_csr_sign($solicitud, null, $clave, 365, ['digest_alg' => 'sha256'])
            ?: throw new RuntimeException('No se pudo firmar el certificado del proveedor simulado.');

        openssl_x509_export($x509, $certificadoPem);
        openssl_pkey_export($clave, $clavePem);

        $registro = new ElectronicCertificate;
        $registro->forceFill([
            'subject' => 'CN=PSFE simulado BMIA',
            'fingerprint' => (string) openssl_x509_fingerprint($x509, 'sha256'),
        ]);

        return self::$certificado = new LoadedCertificate((string) $clavePem, (string) $certificadoPem, $registro);
    }
}
