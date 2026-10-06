<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

use App\Modules\Core\Models\Company;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Providers\Contracts\ElectronicInvoiceProvider;
use App\Modules\ElectronicInvoicing\Providers\Contracts\SignsDocuments;
use App\Modules\ElectronicInvoicing\Providers\Contracts\SubmitsDocuments;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeCatalog;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeConnectionList;
use App\Modules\ElectronicInvoicing\Providers\Psfe\PsfeDriver;
use App\Modules\ElectronicInvoicing\Signature\SignedXml;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Escenario B: proveedores de servicios de facturación electrónica certificados por la DGII (PSFE),
 * VARIOS y en orden, para que una factura no se quede sin salir porque uno esté caído.
 *
 * Cada PSFE tiene su propia API, así que esto no habla con ninguno: le pasa cada llamada al conector
 * (`PsfeDriver`) de la conexión que toca, con sus credenciales descifradas. Sin conexiones responde
 * «no configurado», como antes de existir.
 *
 * El RESPALDO al enviar (`submit`):
 *   · se prueba la conexión principal y, si falla EL PROVEEDOR, la siguiente;
 *   · un rechazo por los datos de la factura NO pasa a otro: el error está en la factura;
 *   · lo delicado es no enviar dos veces la misma: si el fallo pudo ocurrir con el documento ya
 *     entregado (tiempo agotado, 5xx), antes de usar otro se le pregunta a ese mismo por el e-NCF; si
 *     no se puede ni preguntar, la factura queda pendiente en vez de arriesgar un doble envío;
 *   · un proveedor que no responde queda «en pausa» unos minutos, para que cada factura no espere su
 *     tiempo agotado.
 *
 * Las consultas de estado van SIEMPRE al proveedor que recibió el documento (`pinned()`).
 */
final class PsfeProvider implements ElectronicInvoiceProvider, SignsDocuments, SubmitsDocuments
{
    private const MENSAJE = 'No hay un proveedor certificado (PSFE) conectado. Conéctalo en Facturación electrónica → «Proveedores autorizados».';

    /** Si no es null, solo se usa esta conexión (consultas del documento que ella recibió). */
    private ?string $fijo = null;

    public function __construct(private readonly PsfeCatalog $catalog) {}

    public function name(): string
    {
        return 'psfe';
    }

    /** Una copia que solo usa la conexión `$slug`: para consultar el documento que ella recibió. */
    public function pinned(string $slug): self
    {
        $copia = clone $this;
        $copia->fijo = $slug;

        return $copia;
    }

    public function isConfigured(Company $company): bool
    {
        return $this->conexiones($company) !== [];
    }

    /** El conector de la conexión PRINCIPAL, si hay alguna completa. */
    public function driverFor(Company $company): ?PsfeDriver
    {
        return $this->conexiones($company)[0][0] ?? null;
    }

    /** La principal firma aparte (BMIA le pide la firma y luego envía él el firmado). */
    public function signsFor(Company $company): bool
    {
        $c = $this->driverFor($company)?->capabilities();

        return $c !== null && $c->signs && ! $c->submitsUnsigned;
    }

    /** La principal firma y envía en una llamada a partir del documento sin firmar. */
    public function submitsUnsignedFor(Company $company): bool
    {
        return $this->driverFor($company)?->capabilities()->submitsUnsigned ?? false;
    }

    public function signDocument(Company $company, Environment $env, DOMDocument $unsigned, bool $writeSignatureDate = true): SignedXml
    {
        [$conector, $credenciales] = $this->conexiones($company)[0] ?? throw new RuntimeException(self::MENSAJE);

        if (! $conector->capabilities()->signs) {
            throw new RuntimeException("{$conector->label()} no firma los documentos: sube tu certificado digital.");
        }

        return $conector->sign($company, $credenciales, $env, $unsigned, $writeSignatureDate);
    }

    public function submit(
        Company $company,
        Environment $env,
        ?string $signedXml,
        string $unsignedXml,
        string $fileName,
        bool $summary,
        string $encf,
        ?string $lastVia = null,
    ): ProviderResult {
        $conexiones = $this->conexiones($company, $env);

        if ($conexiones === []) {
            return new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE, delivered: false);
        }

        $notas = [];
        $fallaron = [];

        // Un intento anterior con resultado incierto: primero se le pregunta a quien lo tuvo.
        $previa = $lastVia !== null ? $this->buscar($conexiones, $lastVia) : null;
        if ($previa !== null) {
            [$conector, $credenciales, $slug] = $previa;
            $consulta = $conector->queryResult($company, $credenciales, $env, $encf);

            if ($consulta->outcome === ProviderOutcome::TransientError) {
                $this->pausar($company, $slug, $consulta);

                return $consulta->via($slug, ["{$conector->label()} no respondió al preguntarle por {$encf}: queda pendiente para no enviarlo dos veces."]);
            }

            if ($consulta->outcome !== ProviderOutcome::NotFound) {
                return $this->comoRecibido($consulta, $encf)->via($slug, ["{$conector->label()} ya lo tenía: no se reenvía."]);
            }
        }

        $ultimo = null;
        $huboPasajero = false;

        foreach ($conexiones as [$conector, $credenciales, $slug]) {
            if ($this->enPausa($company, $slug)) {
                $notas[] = "{$conector->label()} en pausa por fallos recientes.";
                $fallaron[] = $slug;
                $huboPasajero = true;

                continue;
            }

            $r = match (true) {
                $conector->capabilities()->submitsUnsigned => $conector->send($company, $credenciales, $env, $unsignedXml, $fileName),
                $signedXml === null => null,
                $summary => $conector->sendSummary($company, $credenciales, $env, $signedXml, $fileName),
                default => $conector->send($company, $credenciales, $env, $signedXml, $fileName),
            };

            if ($r === null) {
                $notas[] = "{$conector->label()} necesita el documento firmado en BMIA y este no lo está.";

                continue;
            }

            if ($this->esFalloSeguro($r)) {
                // No llegó a salir: probar con el siguiente no puede duplicarlo.
                $this->pausar($company, $slug, $r);
                $huboPasajero = $huboPasajero || $r->outcome === ProviderOutcome::TransientError;
                $notas[] = "{$conector->label()}: {$r->summary()}";
                $fallaron[] = $slug;
                $ultimo = $r->via($slug);

                continue;
            }

            if ($r->outcome === ProviderOutcome::TransientError) {
                // Pudo recibirlo: se le pregunta antes de usar otro.
                $consulta = $conector->queryResult($company, $credenciales, $env, $encf);

                if ($consulta->outcome !== ProviderOutcome::NotFound) {
                    // Sin poder confirmarlo, queda pendiente; y este proveedor, en pausa como cualquier caído.
                    if ($consulta->outcome === ProviderOutcome::TransientError) {
                        $this->pausar($company, $slug, $r);
                    }

                    $nota = $consulta->outcome === ProviderOutcome::TransientError
                        ? "{$conector->label()} no respondió ({$r->summary()}) y no se pudo confirmar si lo recibió: queda pendiente para no enviarlo dos veces."
                        : "{$conector->label()} tardó en responder, pero lo había recibido.";

                    return ($consulta->outcome === ProviderOutcome::TransientError ? $r : $this->comoRecibido($consulta, $encf))->via($slug, [...$notas, $nota], $fallaron);
                }

                $this->pausar($company, $slug, $r);
                $huboPasajero = true;
                $notas[] = "{$conector->label()}: {$r->summary()} (confirmado que no lo recibió).";
                $fallaron[] = $slug;
                $ultimo = $r->via($slug);

                continue;
            }

            return $r->via($slug, $notas, $fallaron);
        }

        // Ninguno lo envió. Si alguno falló por comunicación (o está en pausa por eso), la factura queda
        // PENDIENTE y el procesador la reintenta; si todos fallaron por configuración, queda en error.
        $pasajero = $huboPasajero;

        return new ProviderResult(
            $pasajero ? ProviderOutcome::TransientError : ($ultimo?->outcome ?? ProviderOutcome::NotConfigured),
            error: implode(' ', $notas) ?: ($ultimo?->summary() ?? self::MENSAJE),
            delivered: false,
            notes: $notas,
            failoverFrom: $fallaron,
        );
    }

    public function send(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, $env, fn (PsfeDriver $d, array $c) => $d->send($company, $c, $env, $signedXml, $fileName));
    }

    public function sendSummary(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, $env, fn (PsfeDriver $d, array $c) => $d->sendSummary($company, $c, $env, $signedXml, $fileName));
    }

    public function queryResult(Company $company, Environment $env, string $trackId): ProviderResult
    {
        return $this->con($company, $env, fn (PsfeDriver $d, array $c) => $d->queryResult($company, $c, $env, $trackId));
    }

    public function sendCommercialApproval(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, $env, fn (PsfeDriver $d, array $c) => $d->sendCommercialApproval($company, $c, $env, $signedXml, $fileName));
    }

    public function voidRange(Company $company, Environment $env, string $signedXml, string $fileName): ProviderResult
    {
        return $this->con($company, $env, fn (PsfeDriver $d, array $c) => $d->voidRange($company, $c, $env, $signedXml, $fileName));
    }

    public function findReceiver(Company $company, Environment $env, string $taxId): ReceiverLookup
    {
        $principal = $this->conexiones($company, $env)[0] ?? null;

        return $principal === null
            ? new ReceiverLookup(ReceiverLookup::UNSUPPORTED, error: self::MENSAJE)
            : $principal[0]->findReceiver($company, $principal[1], $env, $taxId);
    }

    /**
     * Un proveedor que YA tenía el documento pero sin respuesta de la DGII («en proceso») cuenta como
     * recibido: así el documento pasa a «recibido» y el procesador lo consulta después, en vez de
     * quedarse en «enviando», que nadie recoge.
     */
    private function comoRecibido(ProviderResult $consulta, string $encf): ProviderResult
    {
        return $consulta->outcome === ProviderOutcome::InProcess
            ? new ProviderResult(ProviderOutcome::Received, trackId: $consulta->trackId ?? $encf, status: $consulta->status, raw: $consulta->raw, delivered: true)
            : $consulta;
    }

    /**
     * Fallo del proveedor que se sabe que NO entregó el documento: no está configurado o el propio
     * conector dice que no llegó a salir. Solo con estos se pasa al siguiente sin preguntar.
     */
    private function esFalloSeguro(ProviderResult $r): bool
    {
        return $r->outcome === ProviderOutcome::NotConfigured
            || ($r->delivered === false && in_array($r->outcome, [ProviderOutcome::TransientError, ProviderOutcome::PermanentError], true));
    }

    private function enPausa(Company $company, string $slug): bool
    {
        return Cache::has($this->clavePausa($company, $slug));
    }

    /** Solo los fallos de comunicación pausan: unas credenciales malas no se arreglan esperando. */
    private function pausar(Company $company, string $slug, ProviderResult $r): void
    {
        if ($r->outcome === ProviderOutcome::TransientError) {
            Cache::put($this->clavePausa($company, $slug), true, now()->addMinutes((int) config('ecf_psfe.pause_minutes', 5)));
        }
    }

    private function clavePausa(Company $company, string $slug): string
    {
        return "ecf:psfe-pausa:{$company->id}:{$slug}";
    }

    /** @param  callable(PsfeDriver, array<string, string>): ProviderResult  $llamada */
    private function con(Company $company, Environment $env, callable $llamada): ProviderResult
    {
        $principal = $this->conexiones($company, $env)[0] ?? null;

        return $principal === null
            ? new ProviderResult(ProviderOutcome::NotConfigured, error: self::MENSAJE, delivered: false)
            : $llamada($principal[0], $principal[1])->via($principal[2]);
    }

    /**
     * @param  list<array{0: PsfeDriver, 1: array<string, string>, 2: string}>  $conexiones
     * @return array{0: PsfeDriver, 1: array<string, string>, 2: string}|null
     */
    private function buscar(array $conexiones, string $slug): ?array
    {
        foreach ($conexiones as $c) {
            if ($c[2] === $slug) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Las conexiones utilizables, en su orden: con su conector en el catálogo, los datos obligatorios
     * completos y, si se da el ambiente, disponibles en él. Con `pinned()`, solo esa.
     *
     * @return list<array{0: PsfeDriver, 1: array<string, string>, 2: string}>
     */
    private function conexiones(Company $company, ?Environment $env = null): array
    {
        $ajustes = ElectronicInvoicingSettings::paraEmpresa($company);
        $env ??= $ajustes->environment;
        $lista = [];

        foreach (PsfeConnectionList::read($ajustes->provider_config) as $c) {
            $slug = (string) $c['psfe'];
            $conector = $this->catalog->find($slug);

            if ($conector === null || ! $conector->availableIn($env) || ($this->fijo !== null && $this->fijo !== $slug)) {
                continue;
            }

            $credenciales = array_map('strval', (array) ($c['credentials'] ?? []));
            $completa = true;

            foreach ($conector->fields() as $campo) {
                if ($campo->required && blank($credenciales[$campo->name] ?? null)) {
                    $completa = false;
                }
            }

            if ($completa) {
                $lista[] = [$conector, $credenciales, $slug];
            }
        }

        return $lista;
    }
}
