<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CompanyScope;
use App\Modules\ElectronicInvoicing\Contingency\ContingencyService;
use App\Modules\ElectronicInvoicing\Domain\EcfDocument;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoiceAuditLog;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Ncf\ElectronicNcfService;
use App\Modules\ElectronicInvoicing\Providers\ProviderOutcome;
use App\Modules\ElectronicInvoicing\Providers\ProviderResolver;
use App\Modules\ElectronicInvoicing\Providers\ProviderResult;
use App\Modules\ElectronicInvoicing\Signature\CertificateException;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\ElectronicInvoicing\Signature\XmlSigner;
use App\Modules\ElectronicInvoicing\Storage\FiscalDocumentStore;
use Carbon\CarbonImmutable;
use DOMDocument;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * El único punto por el que pasa un e-CF de BMIA:
 *
 *   documento comercial → e-CF canónico → (validación) → e-NCF → XML → firma → envío → respuesta → estado
 *
 * Controladores, vistas y demás módulos solo hablan con esta clase; nadie más llama a la DGII ni a un
 * proveedor. Cada cambio de estado lo valida `EcfStatus::canTransitionTo()` y queda en la bitácora.
 *
 * Reglas que protege:
 *   · el e-NCF se reserva DESPUÉS de comprobar que el documento es válido (con un número provisional):
 *     un error de datos no quema números autorizados por la DGII;
 *   · sin certificado no se reserva número (no se podría firmar);
 *   · el XML firmado se guarda byte a byte antes de enviarlo: si el envío falla, se reenvía el mismo;
 *   · un fallo pasajero deja el documento PENDIENTE de envío (nunca se pierde) y abre contingencia;
 *   · un rechazo con `secuenciaUtilizada = false` devuelve el número al uso [DT p.24].
 */
final class ElectronicInvoiceService
{
    public function __construct(
        private readonly EcfXmlGenerator $generator,
        private readonly ElectronicNcfService $ncf,
        private readonly CertificateVault $certificates,
        private readonly XmlSigner $signer,
        private readonly FiscalDocumentStore $files,
        private readonly ProviderResolver $providers,
        private readonly ContingencyService $contingency,
    ) {}

    /** Prepara y envía en el acto. */
    public function issue(
        Company $company,
        EcfDocument $draft,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $userId = null,
        ?string $ip = null,
    ): ElectronicInvoice {
        $ecf = $this->prepare($company, $draft, $sourceType, $sourceId, $userId, $ip);

        return $ecf->status === EcfStatus::PendienteEnvio ? $this->send($ecf, $userId, $ip) : $ecf;
    }

    /**
     * Valida, numera, genera, firma y deja el documento PENDIENTE de envío, sin hablar con nadie.
     *
     * Separado del envío para quien emite dentro de una transacción (la factura de una venta): la
     * llamada a la DGII se hace DESPUÉS de confirmar la transacción, así una venta que se revierte no
     * deja un e-CF ya enviado. Lanza EcfValidationException o CertificateException sin consumir número.
     */
    public function prepare(
        Company $company,
        EcfDocument $draft,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $userId = null,
        ?string $ip = null,
    ): ElectronicInvoice {
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $env = $settings->environment;

        // 1. ¿Es válido? Con un e-NCF provisional del tipo, antes de tocar la secuencia.
        $provisional = $draft->withNumbering($draft->type->prefix().'0000000001', CarbonImmutable::now()->addYear()->endOfYear());
        $prueba = $this->generator->generate($provisional);

        if (! $prueba->isValid()) {
            throw new EcfValidationException($prueba->errors);
        }

        // 2. Sin certificado no se podría firmar: no se reserva número.
        if ($this->certificates->active($company) === null) {
            throw CertificateException::missing();
        }

        // 3. Número definitivo y documento.
        $numero = $this->ncf->allocate((int) $company->id, $env, $draft->type);
        $doc = $draft->withNumbering($numero['encf'], $numero['sequence']->expires_at);
        $resultado = $this->generator->generate($doc);
        $impuestos = $resultado->tax;

        $ecf = ElectronicInvoice::create([
            'company_id' => $company->id,
            'environment' => $env,
            'ecf_type' => $doc->type,
            'e_ncf' => $numero['encf'],
            'status' => EcfStatus::Generado,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'electronic_ncf_sequence_id' => $numero['sequence']->id,
            'contingency_id' => $this->contingency->open((int) $company->id, $env)?->id,
            'issue_date' => $doc->issueDate->toDateString(),
            'buyer_tax_id' => $doc->buyer?->taxId ?? $doc->buyer?->foreignId,
            'buyer_name' => $doc->buyer?->legalName,
            'total' => $impuestos?->total ?? '0',
            'itbis_total' => $impuestos?->itbisTotal ?? '0',
            'sends_summary' => $this->generator->sendsSummary($doc, $impuestos?->total),
            'provider' => $settings->provider,
            'spec_version' => (string) config('ecf.spec.version', '1.0'),
            'schema_date' => $resultado->schemaDate,
            'created_by' => $userId,
        ]);

        $this->log($ecf, 'e-CF generado', null, EcfStatus::Generado, $userId, $ip, ['reutilizado' => $numero['reused']]);

        if (! $resultado->isValid()) {
            // No debería pasar (ya se validó), pero el número está consumido: el documento queda con su error.
            $this->moverA($ecf, EcfStatus::Error, 'Error al generar el XML', $userId, $ip, ['errores' => array_map(fn ($e) => $e->message, $resultado->errors)]);

            return $ecf;
        }

        // 4. XML, firma y (si aplica) resumen de consumo.
        $this->files->put($ecf, 'original', (string) $resultado->xml->saveXML());
        $this->moverA($ecf, EcfStatus::XmlGenerado, 'XML generado', $userId, $ip);

        try {
            $this->firmar($company, $ecf, $doc, $resultado->xml);
        } catch (Throwable $e) {
            $ecf->forceFill(['last_error' => $e->getMessage()])->save();
            $this->moverA($ecf, EcfStatus::Error, 'Error al firmar', $userId, $ip, ['error' => $e->getMessage()]);

            return $ecf;
        }

        $this->moverA($ecf, EcfStatus::PendienteEnvio, 'Listo para enviar', $userId, $ip);

        return $ecf;
    }

    public function send(ElectronicInvoice $ecf, ?int $userId = null, ?string $ip = null): ElectronicInvoice
    {
        if (! in_array($ecf->status, [EcfStatus::PendienteEnvio, EcfStatus::Contingencia, EcfStatus::Error], true)) {
            return $ecf;
        }

        $company = Company::query()->findOrFail($ecf->company_id);
        $settings = ElectronicInvoicingSettings::paraEmpresa($company);
        $proveedor = $this->providers->for($settings);

        $nombre = $this->nombreArchivo($settings, $ecf);
        $resumen = $ecf->sends_summary;

        // Se lee ANTES de pasar a «enviando»: si falta o su huella no coincide, el documento queda en
        // error a la vista en vez de atascado en «enviando» (el procesador no recoge ese estado).
        try {
            $xml = $this->files->get($ecf->file($resumen ? 'rfce_firmado' : 'firmado') ?? throw new LogicException('Falta el XML firmado.'));
        } catch (Throwable $e) {
            $ecf->forceFill(['last_error' => $e->getMessage(), 'next_attempt_at' => null])->save();

            if ($ecf->status->canTransitionTo(EcfStatus::Error)) {
                $this->moverA($ecf, EcfStatus::Error, 'No se pudo leer el XML firmado', $userId, $ip, ['error' => $e->getMessage()]);
            }

            return $ecf;
        }

        if ($ecf->status === EcfStatus::Error) {
            $this->moverA($ecf, EcfStatus::PendienteEnvio, 'Reintento de envío', $userId, $ip);
        }

        $this->moverA($ecf, EcfStatus::Enviando, 'Envío iniciado', $userId, $ip, ['proveedor' => $proveedor->name()]);

        $r = $resumen
            ? $proveedor->sendSummary($company, $ecf->environment, $xml, $nombre)
            : $proveedor->send($company, $ecf->environment, $xml, $nombre);

        $this->guardarRespuesta($ecf, $resumen ? 'send_summary' : 'send', $proveedor->name(), $r, $userId);
        $ecf->forceFill(['attempts' => $ecf->attempts + 1, 'sent_at' => now()])->save();

        return $this->aplicar($ecf, $r, $userId, $ip);
    }

    /**
     * Envía cuando la transacción en curso se confirme (en el acto si no hay ninguna). Si falla, el
     * documento queda pendiente y lo retoma el procesador: quien factura nunca espera ni se cae por
     * la DGII, y una transacción revertida no deja nada enviado.
     */
    public function sendAfterCommit(ElectronicInvoice $ecf): void
    {
        $id = (int) $ecf->id;

        DB::afterCommit(function () use ($id): void {
            try {
                $doc = ElectronicInvoice::query()->withoutGlobalScope(CompanyScope::class)->find($id);

                if ($doc !== null) {
                    $this->send($doc);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Consulta el resultado de un documento recibido (con TrackId). */
    public function query(ElectronicInvoice $ecf, ?int $userId = null): ElectronicInvoice
    {
        if ($ecf->status !== EcfStatus::Recibido || $ecf->track_id === null) {
            return $ecf;
        }

        $company = Company::query()->findOrFail($ecf->company_id);
        $proveedor = $this->providers->for(ElectronicInvoicingSettings::paraEmpresa($company));

        $r = $proveedor->queryResult($company, $ecf->environment, $ecf->track_id);
        $this->guardarRespuesta($ecf, 'query', $proveedor->name(), $r, $userId);

        return $this->aplicar($ecf, $r, $userId, null);
    }

    /**
     * Envíos pendientes y consultas vencidas, los más antiguos primero, hasta agotar el presupuesto de
     * tiempo (la función de Vercel corta en ~10 s). Lo llama /tareas/ecf-procesar.
     *
     * @return array{enviados: int, consultados: int, restantes: int}
     */
    public function processPending(int $budgetSeconds = 8): array
    {
        $limite = microtime(true) + $budgetSeconds;
        $enviados = 0;
        $consultados = 0;

        $pendientes = ElectronicInvoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('status', [EcfStatus::PendienteEnvio->value, EcfStatus::Contingencia->value, EcfStatus::Recibido->value])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($pendientes as $ecf) {
            if (microtime(true) >= $limite) {
                break;
            }

            // Un documento que falla de forma inesperada no puede dejar sin turno a los demás.
            try {
                if ($ecf->status === EcfStatus::Recibido) {
                    $this->query($ecf);
                    $consultados++;
                } else {
                    $this->send($ecf);
                    $enviados++;
                }
            } catch (Throwable $e) {
                report($e);
                $ecf->forceFill(['next_attempt_at' => $this->siguienteIntento($ecf), 'last_error' => $e->getMessage()])->save();
            }
        }

        return ['enviados' => $enviados, 'consultados' => $consultados, 'restantes' => max(0, $pendientes->count() - $enviados - $consultados)];
    }

    private function firmar(Company $company, ElectronicInvoice $ecf, EcfDocument $doc, DOMDocument $xml): void
    {
        $certificado = $this->certificates->load($company);
        $firmado = $this->signer->sign($xml, $certificado, now());

        $this->files->put($ecf, 'firmado', $firmado->xml);
        $ecf->forceFill([
            'security_code' => $firmado->securityCode,
            'signed_at' => $firmado->signedAt,
            'certificate_fingerprint' => $firmado->certificateFingerprint,
        ])->save();

        $this->moverA($ecf, EcfStatus::Firmado, 'XML firmado', null, null, ['certificado' => $firmado->certificateFingerprint]);

        if ($ecf->sends_summary) {
            $rfce = $this->generator->generateRfce($doc, $firmado->securityCode);

            if (! $rfce->isValid()) {
                throw new EcfValidationException($rfce->errors);
            }

            $this->files->put($ecf, 'rfce', (string) $rfce->xml->saveXML());
            $this->files->put($ecf, 'rfce_firmado', $this->signer->sign($rfce->xml, $certificado, now(), writeSignatureDate: false)->xml);
        }
    }

    /** Traduce el resultado del proveedor a un estado del documento. */
    private function aplicar(ElectronicInvoice $ecf, ProviderResult $r, ?int $userId, ?string $ip): ElectronicInvoice
    {
        $detalle = ['resultado' => $r->outcome->value, 'mensaje' => $r->summary()];

        switch ($r->outcome) {
            case ProviderOutcome::Received:
                $ecf->forceFill(['track_id' => $r->trackId, 'next_attempt_at' => now()->addMinute(), 'last_error' => null])->save();
                $this->moverA($ecf, EcfStatus::Recibido, 'Documento recibido por la DGII', $userId, $ip, $detalle + ['track_id' => $r->trackId]);
                $this->contingency->recordRecovery($ecf->company_id, $ecf->environment);
                break;

            case ProviderOutcome::Accepted:
            case ProviderOutcome::AcceptedConditional:
                $ecf->forceFill(['resolved_at' => now(), 'next_attempt_at' => null, 'last_error' => null])->save();
                $this->moverA($ecf, $r->outcome === ProviderOutcome::Accepted ? EcfStatus::Aceptado : EcfStatus::AceptadoCondicional, 'Documento aceptado', $userId, $ip, $detalle);
                $this->contingency->recordRecovery($ecf->company_id, $ecf->environment);
                break;

            case ProviderOutcome::Rejected:
                $ecf->forceFill(['resolved_at' => now(), 'next_attempt_at' => null, 'last_error' => $r->summary()])->save();
                $this->moverA($ecf, EcfStatus::Rechazado, 'Documento rechazado', $userId, $ip, $detalle);
                $this->contingency->recordRecovery($ecf->company_id, $ecf->environment);

                // [DT p.24] false = el número puede reutilizarse.
                if ($r->sequenceUsed === false) {
                    $this->ncf->release($ecf->company_id, $ecf->environment, $ecf->e_ncf, 'Rechazado por la DGII con secuenciaUtilizada=false: '.$r->summary());
                    $this->log($ecf, 'e-NCF liberado para reutilizar', null, null, $userId, $ip, $detalle);
                }
                break;

            case ProviderOutcome::InProcess:
            case ProviderOutcome::NotFound:
                // Sigue esperando: se vuelve a consultar más tarde.
                $ecf->forceFill(['next_attempt_at' => $this->siguienteIntento($ecf)])->save();
                $this->log($ecf, $r->outcome === ProviderOutcome::InProcess ? 'La DGII sigue procesando' : 'TrackId no encontrado todavía', null, null, $userId, $ip, $detalle);
                break;

            case ProviderOutcome::TransientError:
                $ecf->forceFill(['next_attempt_at' => $this->siguienteIntento($ecf), 'last_error' => $r->summary()])->save();
                $contingencia = $this->contingency->recordFailure($ecf->company_id, $ecf->environment, $r->summary());
                $ecf->forceFill(['contingency_id' => $ecf->contingency_id ?? $contingencia->id])->save();

                if ($ecf->status === EcfStatus::Enviando) {
                    $this->moverA($ecf, EcfStatus::PendienteEnvio, 'Sin comunicación: queda pendiente de envío', $userId, $ip, $detalle);
                } else {
                    $this->log($ecf, 'Sin comunicación al consultar', null, null, $userId, $ip, $detalle);
                }
                break;

            case ProviderOutcome::PermanentError:
            case ProviderOutcome::NotConfigured:
                $ecf->forceFill(['last_error' => $r->summary(), 'next_attempt_at' => null])->save();
                $this->moverA($ecf, EcfStatus::Error, 'Error al enviar', $userId, $ip, $detalle);
                break;
        }

        return $ecf->refresh();
    }

    private function siguienteIntento(ElectronicInvoice $ecf): CarbonImmutable
    {
        $escalones = (array) config('ecf.retry_backoff_minutes', [1, 5, 15, 60]);
        // `attempts` ya cuenta el envío que acaba de fallar: el primer fallo espera el primer escalón.
        $minutos = (int) ($escalones[min(max(0, $ecf->attempts - 1), count($escalones) - 1)] ?? 60);

        return CarbonImmutable::now()->addMinutes($minutos);
    }

    private function guardarRespuesta(ElectronicInvoice $ecf, string $operacion, string $proveedor, ProviderResult $r, ?int $userId): void
    {
        $ecf->responses()->create([
            'company_id' => $ecf->company_id,
            'operation' => $operacion,
            'provider' => $proveedor,
            'outcome' => $r->outcome->value,
            'http_status' => $r->httpStatus,
            'dgii_code' => $r->code,
            'dgii_status' => $r->status !== null ? mb_substr($r->status, 0, 60) : null,
            'track_id' => $r->trackId,
            'messages' => $r->messages !== [] ? $r->messages : ($r->error !== null ? [['codigo' => null, 'valor' => $r->error]] : null),
            'sequence_used' => $r->sequenceUsed,
            'raw' => $r->raw !== null ? mb_substr($r->raw, 0, 20000) : null,
            'user_id' => $userId,
        ]);
    }

    /** Cambio de estado validado por la máquina de estados y registrado en la bitácora. */
    private function moverA(ElectronicInvoice $ecf, EcfStatus $to, string $accion, ?int $userId, ?string $ip, array $detalle = []): void
    {
        $desde = $ecf->status;

        if (! $desde->canTransitionTo($to)) {
            throw new LogicException("Cambio de estado no permitido para {$ecf->e_ncf}: {$desde->value} → {$to->value}.");
        }

        DB::transaction(function () use ($ecf, $desde, $to, $accion, $userId, $ip, $detalle): void {
            $ecf->forceFill(['status' => $to])->save();
            $this->log($ecf, $accion, $desde, $to, $userId, $ip, $detalle);
        });
    }

    private function log(ElectronicInvoice $ecf, string $accion, ?EcfStatus $desde, ?EcfStatus $hacia, ?int $userId, ?string $ip, array $detalle = []): void
    {
        ElectronicInvoiceAuditLog::create([
            'company_id' => $ecf->company_id,
            'electronic_invoice_id' => $ecf->id,
            'e_ncf' => $ecf->e_ncf,
            'action' => $accion,
            'from_status' => $desde?->value,
            'to_status' => $hacia?->value,
            'user_id' => $userId ?? auth()->id(),
            'ip' => $ip ?? (app()->runningInConsole() ? null : request()->ip()),
            'details' => $detalle !== [] ? $detalle : null,
        ]);
    }

    /** RNC + e-NCF + .xml [DT p.12]. */
    private function nombreArchivo(ElectronicInvoicingSettings $settings, ElectronicInvoice $ecf): string
    {
        return preg_replace('/\D/', '', (string) $settings->tax_id).$ecf->e_ncf.'.xml';
    }
}
