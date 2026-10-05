<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;
use App\Modules\ElectronicInvoicing\Providers\Contracts\SignsDocuments;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCatalog;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use DOMDocument;
use RuntimeException;

/**
 * Escenario B: un Proveedor de Servicios de Facturación Electrónica certificado por la DGII.
 *
 * Cada PSFE tiene su propia API, así que esto no habla con ninguno: le pasa cada llamada al conector
 * que la empresa conectó en «Conecta tu proveedor autorizado» (`PsfeConnectionService`), con sus
 * credenciales descifradas. Sin conector o sin credenciales responde «no configurado» con un mensaje
 * claro, como antes de existir la conexión.
 *
 * Qué guarda la empresa (cifrado, en `provider_config`): `psfe` (slug del catálogo), `credentials`,
 * y lo último que se supo de la conexión (`account`, `connected_at`, `checked_at`, `check_ok`,
 * `check_message`).
 */
final class PsfeProvider implements ElectronicInvoiceProvider, SignsDocuments
{
    private const MENSAJE = 'No hay un proveedor certificado (PSFE) conectado. Conéctalo en Facturación electrónica → «Conecta tu proveedor autorizado».';

    public function __construct(private readonly PsfeCatalog $catalog) {}

    public function name(): string
    {
        return 'psfe';
    }

    public function isConfigured(Company $company): bool
    {
        return $this->conexion($company) !== null;
    }

    /** El conector conectado de la empresa, si lo hay y está completo. */
    public function driverFor(Company $company): ?PsfeDriver
    {
        return $this->conexion($company)[0] ?? null;
    }

    public function signsFor(Company $company): bool
    {
        return $this->driverFor($company)?->capabilities()->signs ?? false;
    }

    public function signDocument(Company $company, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate = true): SignedXml
    {
        [$conector, $credenciales] = $this->conexion($company) ?? throw new RuntimeException(self::MENSAJE);

        if (! $conector->capabilities()->signs) {
            throw new RuntimeException("{$conector->label()} no firma los documentos: sube tu certificado digital.");
        }

        return $conector->sign($company, $credenciales, $env, $unsigned, $writeSignatureDate);
    }

    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, fn (PsfeDriver $d, array $c) => $d->send($company, $c, $env, $signedXml, $fileName));
    }

    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, fn (PsfeDriver $d, array $c) => $d->sendSummary($company, $c, $env, $signedXml, $fileName));
    }

    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult
    {
        return $this->con($company, fn (PsfeDriver $d, array $c) => $d->queryResult($company, $c, $env, $trackId));
    }

    public function sendCommercialApproval(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, fn (PsfeDriver $d, array $c) => $d->sendCommercialApproval($company, $c, $env, $signedXml, $fileName));
    }

    public function voidRange(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, fn (PsfeDriver $d, array $c) => $d->voidRange($company, $c, $env, $signedXml, $fileName));
    }

    public function findReceiver(Company $company, Environment $env, string $taxId): ReceiverLookup
    {
        $conexion = $this->conexion($company);

        return $conexion === null
            ? new ReceiverLookup(ReceiverLookup::UNSUPPORTED, error: self::MENSAJE)
            : $conexion[0]->findReceiver($company, $conexion[1], $env, $taxId);
    }

    /** @param  callable(PsfeDriver, array<string, string>): ProviderResult  $llamada */
    private function con(Company $company, callable $llamada): ProviderResult
    {
        $conexion = $this->conexion($company);

        return $conexion === null
            ? new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE)
            : $llamada($conexion[0], $conexion[1]);
    }

    /**
     * El conector y las credenciales de la empresa, o null si falta algo: el conector ya no está en el
     * catálogo, o falta un dato obligatorio.
     *
     * @return array{0: PsfeDriver, 1: array<string, string>}|null
     */
    private function conexion(Company $company): ?array
    {
        $config = (array) (ElectronicInvoicingSettings::paraEmpresa($company)->provider_config ?? []);
        $conector = $this->catalog->find(isset($config['psfe']) ? (string) $config['psfe'] : null);

        if ($conector === null) {
            return null;
        }

        $credenciales = array_map('strval', (array) ($config['credentials'] ?? []));

        foreach ($conector->fields() as $campo) {
            if ($campo->required && blank($credenciales[$campo->name] ?? null)) {
                return null;
            }
        }

        return [$conector, $credenciales];
    }
}
