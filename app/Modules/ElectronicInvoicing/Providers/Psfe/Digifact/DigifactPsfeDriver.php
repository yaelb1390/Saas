<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe\Digifact;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\Psfe\ConnectionCheck;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCapabilities;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeField;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Providers\ReceiverLookup;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Digifact, proveedor certificado (PSFE) de la DGII. Todo sale de su documentación
 * (https://documentacion.digifact.com/do/api.md V1.0.4 y do/nuc/json.md V1.0.7, 2026-10-05):
 *
 *   · FIRMA Y ENVÍA en una llamada (`POST /v2/transform/nuc_json`) a partir de su formato NUC, con el
 *     certificado que la empresa le entregó A ELLOS: BMIA no firma ni pide el .p12.
 *   · La numeración la pone BMIA: el e-NCF va en `Secuencia` y se comprueba que Digifact certificó
 *     ese mismo número (`batch`).
 *   · El estado ante la DGII se consulta aparte (`SHARED_GETRESULTADOENVIO`, por e-NCF).
 *   · No documenta anulación, aprobación comercial ni el directorio de receptores: esas operaciones
 *     responden «no disponible» y no se inventan.
 */
final class DigifactPsfeDriver implements PsfeDriver
{
    public function __construct(
        private readonly DigifactClient $client,
        private readonly DigifactNucMapper $mapper,
    ) {}

    public function slug(): string
    {
        return 'digifact';
    }

    public function label(): string
    {
        return 'Digifact';
    }

    public function description(): string
    {
        return 'Firma por ti con el certificado que les entregaste y envía a la DGII. Pide el usuario y la contraseña de tu cuenta a Digifact.';
    }

    public function fields(): array
    {
        return [
            new PsfeField('usuario', 'Usuario de Digifact', help: 'El que te dio Digifact, sin el «DO.» ni el RNC: BMIA los añade.', maxLength: 100),
            new PsfeField('clave', 'Contraseña', secret: true, maxLength: 200),
        ];
    }

    public function capabilities(): PsfeCapabilities
    {
        return new PsfeCapabilities(signs: true, submitsUnsigned: true);
    }

    public function availableIn(Environment $env): bool
    {
        return true;
    }

    public function testConnection(Company $company, #[SensitiveParameter] array $credentials, Environment $env): ConnectionCheck
    {
        if ($this->client->rnc($company) === '') {
            return new ConnectionCheck(false, 'Primero escribe el RNC de tu empresa en «Datos fiscales y ambiente»: Digifact lo necesita para identificarte.');
        }

        try {
            $r = $this->client->request($company, $credentials, $env, fn (PendingRequest $h, string $base) => $h->get($base.'/SHAREDINFO', [
                'TAXID' => $this->client->rnc($company),
                'USERNAME' => $credentials['usuario'] ?? '',
                'DATA1' => 'SHARED_GETINFORTAXID',
                'DATA2' => 'RNC|'.$this->client->rnc($company),
            ]));
        } catch (DigifactAuthException $e) {
            return new ConnectionCheck(false, $e->getMessage());
        } catch (ConnectionException) {
            return new ConnectionCheck(false, 'No se pudo comunicar con Digifact. Inténtalo de nuevo en unos minutos.');
        }

        if (! $r->successful()) {
            return new ConnectionCheck(false, "Digifact respondió con un error (HTTP {$r->status()}).");
        }

        $nombre = (string) ($r->json('RESPONSE.0.NAME') ?? '');

        return new ConnectionCheck(true, 'Conexión correcta con Digifact.', $nombre !== '' ? $nombre : 'RNC '.$this->client->rnc($company));
    }

    public function sign(Company $company, #[SensitiveParameter] array $credentials, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate): SignedXml
    {
        // Digifact firma al certificar (`send`); no ofrece una firma suelta.
        throw new LogicException('Digifact firma al enviar: no tiene una operación de solo firma.');
    }

    /** Con `submitsUnsigned`, `$signedXml` es el XML original SIN firmar. */
    public function send(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        try {
            $nuc = $this->mapper->fromDgiiXml($signedXml);
            $encf = $this->mapper->encf($signedXml);
        } catch (DigifactUnsupportedDocument $e) {
            // No sabe enviarlo: no salió nada, el respaldo puede intentarlo.
            return new ProviderResult(ProviderOutcome::NotConfigured, error: $e->getMessage(), delivered: false);
        }

        // El acceso primero y aparte: si falla aquí, el documento seguro que no salió.
        try {
            $this->client->token($company, $credentials, $env);
        } catch (DigifactAuthException $e) {
            return new ProviderResult(ProviderOutcome::PermanentError, error: $e->getMessage(), delivered: false);
        } catch (Throwable $e) {
            return new ProviderResult(ProviderOutcome::TransientError, error: 'Sin comunicación con Digifact: '.$e->getMessage(), delivered: false);
        }

        try {
            $r = $this->client->request($company, $credentials, $env, fn (PendingRequest $h, string $base) => $h
                ->withQueryParameters(['TAXID' => $this->client->rnc($company), 'FORMAT' => 'XML', 'USERNAME' => $credentials['usuario'] ?? ''])
                ->post($base.'/v2/transform/nuc_json', $nuc));
        } catch (DigifactAuthException $e) {
            return new ProviderResult(ProviderOutcome::PermanentError, error: $e->getMessage(), delivered: false);
        } catch (ConnectionException $e) {
            return new ProviderResult(ProviderOutcome::TransientError, error: 'Sin comunicación con Digifact: '.$e->getMessage(), delivered: DigifactClient::neverReached($e) ? false : null);
        }

        return $this->respuestaCertificacion($r, $encf);
    }

    public function sendSummary(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        // Digifact recibe la factura de consumo completa y se ocupa él del resumen.
        return $this->send($company, $credentials, $env, $signedXml, $fileName);
    }

    /**
     * `$trackId` es el e-NCF. Si la DGII aún no ha contestado, se mira si Digifact tiene el documento
     * (`SHARED_GETDTEINFO`): con él → en proceso; sin él → no encontrado (y solo entonces el respaldo
     * puede enviarlo sin duplicar).
     */
    public function queryResult(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $trackId): ProviderResult
    {
        try {
            $r = $this->compartido($company, $credentials, $env, 'SHARED_GETRESULTADOENVIO', 'ENCF|'.$trackId);

            // Una respuesta que no se entiende NO es «no lo tiene»: tomarla así permitiría que el
            // respaldo enviara de nuevo un documento que Digifact sí recibió.
            if (! $r->successful() || ! is_array($r->json())) {
                return new ProviderResult(ProviderOutcome::TransientError, trackId: $trackId, httpStatus: $r->status(), error: "Digifact no respondió a la consulta (HTTP {$r->status()}).");
            }

            $fila = $r->json('RESPONSE.0');

            if (! is_array($fila) || ($fila['Estado'] ?? '') === '') {
                $info = $this->compartido($company, $credentials, $env, 'SHARED_GETDTEINFO', 'AUTHNUMBER|'.$trackId);

                if (! $info->successful() || ! is_array($info->json())) {
                    return new ProviderResult(ProviderOutcome::TransientError, trackId: $trackId, httpStatus: $info->status(), error: "Digifact no respondió a la consulta (HTTP {$info->status()}).");
                }

                $existe = $info->json('RESPONSE.0');

                return is_array($existe) && $existe !== []
                    ? new ProviderResult(ProviderOutcome::InProcess, trackId: $trackId, status: 'Digifact lo tiene; la DGII aún no responde.', raw: $r->body())
                    : new ProviderResult(ProviderOutcome::NotFound, trackId: $trackId, status: 'Digifact no tiene este documento.', raw: $r->body());
            }
        } catch (DigifactAuthException $e) {
            return new ProviderResult(ProviderOutcome::TransientError, trackId: $trackId, error: $e->getMessage());
        } catch (ConnectionException $e) {
            return new ProviderResult(ProviderOutcome::TransientError, trackId: $trackId, error: 'Sin comunicación con Digifact: '.$e->getMessage());
        }

        $estado = mb_strtolower((string) $fila['Estado']);
        $mensajes = trim((string) ($fila['Mensajes'] ?? ''), " |");

        return new ProviderResult(
            match (true) {
                str_contains($estado, 'condicional') => ProviderOutcome::AcceptedConditional,
                str_contains($estado, 'aceptado') => ProviderOutcome::Accepted,
                str_contains($estado, 'rechazado') => ProviderOutcome::Rejected,
                default => ProviderOutcome::InProcess,
            },
            trackId: $trackId,
            code: isset($fila['Codigo']) ? (string) $fila['Codigo'] : null,
            status: (string) $fila['Estado'],
            messages: $mensajes !== '' && $mensajes !== '0 -' ? [['codigo' => null, 'valor' => $mensajes]] : [],
            sequenceUsed: isset($fila['SecuenciaUtilizada']) ? filter_var($fila['SecuenciaUtilizada'], FILTER_VALIDATE_BOOLEAN) : null,
            raw: $r->body(),
        );
    }

    public function sendCommercialApproval(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: 'Digifact no ofrece la aprobación comercial por su API.', delivered: false);
    }

    public function voidRange(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::NotConfigured, error: 'Digifact no documenta la anulación de rangos por su API.', delivered: false);
    }

    public function findReceiver(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $taxId): ReceiverLookup
    {
        return new ReceiverLookup(ReceiverLookup::UNSUPPORTED, error: 'Digifact no ofrece la consulta del directorio de receptores electrónicos.');
    }

    /**
     * La respuesta de certificar [API «CERTIFICATE DTE V2»]: `code` 1 = certificado; `batch` el e-NCF;
     * `responseData1` el XML firmado en base64; `infoDetails` los errores. Un 5xx o `code` 500 es un
     * fallo suyo (se reintenta); los demás errores son de los datos (la factura queda en error).
     */
    private function respuestaCertificacion(Response $r, string $encf): ProviderResult
    {
        $json = (array) ($r->json() ?? []);
        $codigo = (string) ($json['code'] ?? '');
        $crudo = json_encode(array_diff_key($json, array_flip(['responseData1', 'responseData2', 'responseData3']))) ?: null;

        if ($r->serverError() || $codigo === '500') {
            return new ProviderResult(ProviderOutcome::TransientError, httpStatus: $r->status(), raw: $crudo, error: "Digifact respondió con un error temporal (HTTP {$r->status()}).");
        }

        $mensajes = array_values(array_map(
            fn ($d): array => is_array($d)
                ? ['codigo' => $d['code'] ?? $d['Code'] ?? $d['codigo'] ?? null, 'valor' => (string) ($d['message'] ?? $d['Message'] ?? $d['mensaje'] ?? json_encode($d))]
                : ['codigo' => null, 'valor' => (string) $d],
            (array) ($json['infoDetails'] ?? []),
        ));

        if ($codigo !== '1') {
            return new ProviderResult(
                ProviderOutcome::PermanentError,
                code: $codigo !== '' ? $codigo : null,
                messages: $mensajes,
                httpStatus: $r->status(),
                raw: $crudo,
                error: 'Digifact no certificó el documento: '.((string) ($json['message'] ?? $json['description'] ?? '') ?: "HTTP {$r->status()}"),
                delivered: true,
            );
        }

        $numero = (string) ($json['batch'] ?? '');

        if ($numero !== '' && $numero !== $encf) {
            return new ProviderResult(
                ProviderOutcome::PermanentError,
                httpStatus: $r->status(),
                raw: $crudo,
                error: "Digifact certificó el número {$numero} en lugar de {$encf}. Revisa con Digifact la asignación de secuencias.",
                delivered: true,
            );
        }

        $firmado = base64_decode((string) ($json['responseData1'] ?? ''), true);

        return new ProviderResult(
            ProviderOutcome::Received,
            trackId: $encf,
            status: (string) ($json['message'] ?? 'Certificado por Digifact'),
            messages: $mensajes,
            httpStatus: $r->status(),
            raw: $crudo,
            delivered: true,
            signedXml: $firmado !== false && $firmado !== '' ? $firmado : null,
        );
    }

    /** @param  array<string, string>  $credentials */
    private function compartido(Company $company, #[SensitiveParameter] array $credentials, Environment $env, string $operacion, string $datos): Response
    {
        return $this->client->request($company, $credentials, $env, fn (PendingRequest $h, string $base) => $h->get($base.'/SHAREDINFO', [
            'TAXID' => $this->client->rnc($company),
            'USERNAME' => $credentials['usuario'] ?? '',
            'DATA1' => $operacion,
            'DATA2' => $datos,
        ]));
    }
}
