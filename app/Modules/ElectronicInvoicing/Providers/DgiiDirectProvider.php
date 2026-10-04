<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Dgii\DgiiClient;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Escenario A: BMIA habla directamente con la DGII como sistema propio del contribuyente.
 *
 * Traduce las respuestas oficiales [DT] al resultado normalizado:
 *   · recepción e-CF {trackId, error, mensaje}: con TrackId = recibido; sin él = error permanente;
 *   · RFCE {codigo, estado, mensajes[], encf, secuenciaUtilizada}: síncrona, por el texto de `estado`;
 *   · consulta {codigo 0–4, estado, mensajes[], secuenciaUtilizada} [DT p.25].
 * Red caída, tiempo agotado o 5xx = fallo pasajero (se reintenta y abre contingencia).
 */
final class DgiiDirectProvider implements ElectronicInvoiceProvider
{
    public function __construct(
        private readonly DgiiClient $client,
        private readonly CertificateVault $certificates,
    ) {}

    public function name(): string
    {
        return 'dgii';
    }

    public function isConfigured(Company $company): bool
    {
        return $this->certificates->active($company) !== null;
    }

    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->intentar(function () use ($company, $env, $signedXml, $fileName): ProviderResult {
            $r = $this->client->postXml($company, $env, 'reception', $signedXml, $fileName);

            if ($r->serverError()) {
                return $this->pasajero($r);
            }

            $trackId = trim((string) $r->json('trackId'));

            if ($r->successful() && $trackId !== '') {
                return new ProviderResult(ProviderOutcome::Received, trackId: $trackId, httpStatus: $r->status(), raw: $r->body());
            }

            return new ProviderResult(
                ProviderOutcome::PermanentError,
                httpStatus: $r->status(),
                raw: $r->body(),
                error: trim(((string) $r->json('error')).' '.((string) $r->json('mensaje'))) ?: "La DGII no aceptó el envío (HTTP {$r->status()}).",
            );
        });
    }

    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->intentar(function () use ($company, $env, $signedXml, $fileName): ProviderResult {
            $r = $this->client->postXml($company, $env, 'rfce_reception', $signedXml, $fileName);

            if ($r->serverError()) {
                return $this->pasajero($r);
            }

            $estado = (string) $r->json('estado');

            return new ProviderResult(
                $r->successful() ? $this->porTextoDeEstado($estado) : ProviderOutcome::PermanentError,
                code: $r->json('codigo') !== null ? (string) $r->json('codigo') : null,
                status: $estado !== '' ? $estado : null,
                messages: $this->mensajes($r),
                sequenceUsed: $this->booleano($r->json('secuenciaUtilizada')),
                httpStatus: $r->status(),
                raw: $r->body(),
            );
        });
    }

    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult
    {
        return $this->intentar(function () use ($company, $env, $trackId): ProviderResult {
            $r = $this->client->get($company, $env, 'result', ['trackid' => $trackId]);

            if ($r->serverError()) {
                return $this->pasajero($r);
            }

            $codigo = $r->json('codigo');
            $clave = is_numeric($codigo) ? (((array) config('ecf.result_codes'))[(int) $codigo] ?? null) : null;

            $resultado = match ($clave) {
                'aceptado' => ProviderOutcome::Accepted,
                'aceptado_condicional' => ProviderOutcome::AcceptedConditional,
                'rechazado' => ProviderOutcome::Rejected,
                'en_proceso' => ProviderOutcome::InProcess,
                'no_encontrado' => ProviderOutcome::NotFound,
                default => ProviderOutcome::PermanentError,
            };

            return new ProviderResult(
                $resultado,
                trackId: $trackId,
                code: $codigo !== null ? (string) $codigo : null,
                status: $r->json('estado') !== null ? (string) $r->json('estado') : null,
                messages: $this->mensajes($r),
                sequenceUsed: $this->booleano($r->json('secuenciaUtilizada')),
                httpStatus: $r->status(),
                raw: $r->body(),
            );
        });
    }

    /**
     * [DT pp.31–33] Respuesta {mensaje[], estado, codigo}: código 1 aprobación comercial aprobada,
     * 2 rechazada (factura no encontrada o no se pudo procesar).
     */
    public function sendCommercialApproval(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->intentar(function () use ($company, $env, $signedXml, $fileName): ProviderResult {
            $r = $this->client->postXml($company, $env, 'commercial_approval', $signedXml, $fileName);

            if ($r->serverError()) {
                return $this->pasajero($r);
            }

            $codigo = (string) $r->json('codigo');
            $mensajes = array_map(fn ($m): array => ['codigo' => null, 'valor' => (string) $m], (array) ($r->json('mensaje') ?? []));

            return new ProviderResult(
                match ($codigo) {
                    '1' => ProviderOutcome::Accepted,
                    '2' => ProviderOutcome::Rejected,
                    default => ProviderOutcome::PermanentError,
                },
                code: $codigo !== '' ? $codigo : null,
                status: $r->json('estado') !== null ? (string) $r->json('estado') : null,
                messages: $mensajes,
                httpStatus: $r->status(),
                raw: $r->body(),
                error: $r->successful() ? null : "La DGII no aceptó la aprobación comercial (HTTP {$r->status()}).",
            );
        });
    }

    /**
     * [DT pp.34–36] Respuesta {rnc, codigo, nombre, mensajes[]}. El DT no publica la tabla de códigos
     * (pending_verification.range_void_codes): HTTP 2xx se toma como procesada y el código y los
     * mensajes se guardan tal cual para que se lean. No existe en certificación.
     */
    public function voidRange(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        if ($env === Environment::Certificacion) {
            return new ProviderResult(ProviderOutcome::PermanentError, error: 'La anulación de rangos no existe en el ambiente de certificación [DT].');
        }

        return $this->intentar(function () use ($company, $env, $signedXml, $fileName): ProviderResult {
            $r = $this->client->postXml($company, $env, 'range_void', $signedXml, $fileName);

            if ($r->serverError()) {
                return $this->pasajero($r);
            }

            $mensajes = array_map(fn ($m): array => ['codigo' => null, 'valor' => (string) $m], (array) ($r->json('mensajes') ?? []));

            return new ProviderResult(
                $r->successful() ? ProviderOutcome::Accepted : ProviderOutcome::Rejected,
                code: $r->json('codigo') !== null ? (string) $r->json('codigo') : null,
                status: $r->json('nombre') !== null ? (string) $r->json('nombre') : null,
                messages: $mensajes,
                httpStatus: $r->status(),
                raw: $r->body(),
                error: $r->successful() ? null : "La DGII no anuló el rango (HTTP {$r->status()}).",
            );
        });
    }

    /**
     * [DT pp.37–39] obtenerdirectorioporrnc: lista con {nombre, rnc, urlRecepcion, urlAceptacion,
     * urlOpcional}. Vacía o 404 = no es receptor electrónico. No existe en certificación.
     */
    public function findReceiver(Company $company, Environment $env, string $taxId): ReceiverLookup
    {
        if ($env === Environment::Certificacion) {
            return new ReceiverLookup(ReceiverLookup::UNSUPPORTED, error: 'El directorio no existe en el ambiente de certificación [DT].');
        }

        try {
            $r = $this->client->get($company, $env, 'directory_by_rnc', ['RNC' => $taxId]);
        } catch (Throwable $e) {
            return new ReceiverLookup(ReceiverLookup::ERROR, error: $e->getMessage());
        }

        if ($r->serverError()) {
            return new ReceiverLookup(ReceiverLookup::ERROR, error: "La DGII respondió con un error temporal (HTTP {$r->status()}).");
        }

        $lista = $r->json();
        $fila = is_array($lista) ? (array_is_list($lista) ? ($lista[0] ?? null) : $lista) : null;

        if ($r->status() === 404 || ! is_array($fila) || blank($fila['urlRecepcion'] ?? null)) {
            return new ReceiverLookup(ReceiverLookup::NOT_ELECTRONIC);
        }

        return new ReceiverLookup(
            ReceiverLookup::FOUND,
            receptionUrl: (string) $fila['urlRecepcion'],
            approvalUrl: filled($fila['urlAceptacion'] ?? null) ? (string) $fila['urlAceptacion'] : null,
            authUrl: filled($fila['urlOpcional'] ?? null) ? (string) $fila['urlOpcional'] : null,
        );
    }

    private function intentar(callable $llamada): ProviderResult
    {
        try {
            return $llamada();
        } catch (ConnectionException $e) {
            return new ProviderResult(ProviderOutcome::TransientError, error: 'Sin comunicación con la DGII: '.$e->getMessage());
        } catch (RequestException $e) {
            return $e->response->serverError()
                ? $this->pasajero($e->response)
                : new ProviderResult(ProviderOutcome::PermanentError, httpStatus: $e->response->status(), raw: $e->response->body(), error: 'La DGII rechazó la autenticación o la petición.');
        } catch (Throwable $e) {
            // Certificado ausente, semilla ilegible…: no se arregla reintentando sin intervención.
            return new ProviderResult(ProviderOutcome::PermanentError, error: $e->getMessage());
        }
    }

    private function pasajero(Response $r): ProviderResult
    {
        return new ProviderResult(ProviderOutcome::TransientError, httpStatus: $r->status(), raw: $r->body(), error: "La DGII respondió con un error temporal (HTTP {$r->status()}).");
    }

    /** [DT p.15] El RFCE responde «Aceptado», «Aceptado Condicional» o «Rechazado». */
    private function porTextoDeEstado(string $estado): ProviderOutcome
    {
        $e = mb_strtolower(trim($estado));

        return match (true) {
            str_contains($e, 'condicional') => ProviderOutcome::AcceptedConditional,
            str_starts_with($e, 'aceptado') => ProviderOutcome::Accepted,
            str_starts_with($e, 'rechazado') => ProviderOutcome::Rejected,
            default => ProviderOutcome::PermanentError,
        };
    }

    /** `secuenciaUtilizada` puede llegar como booleano o como texto («true»/«false»). */
    private function booleano(mixed $valor): ?bool
    {
        return $valor === null ? null : filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** @return list<array{codigo: string|int|null, valor: string|null}> */
    private function mensajes(Response $r): array
    {
        $lista = $r->json('mensajes');

        if (! is_array($lista)) {
            return [];
        }

        return array_values(array_map(
            fn ($m): array => ['codigo' => is_array($m) ? ($m['codigo'] ?? null) : null, 'valor' => is_array($m) ? ($m['valor'] ?? null) : (string) $m],
            $lista,
        ));
    }
}
