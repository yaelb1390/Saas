<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Queues;

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Monitoring\Metrics\MetricsRecorder;
use App\Modules\Core\Monitoring\Metrics\Observation;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;

/**
 * Deja constancia de cada trabajo: cuánto tardó, si falló, y a quién pertenecía.
 *
 * NUNCA llama a `CurrentCompany::forget()` ni toca el tenant activo, aunque procese en `sync` —dentro
 * de la MISMA petición que lo despachó—: tocar el tenant aquí lo dejaría sin ámbito de empresa para
 * el resto de esa petición, y `CompanyScope` dejaría de filtrar. La empresa de un trabajo se lee de
 * SU PROPIO payload (`bmos_company_id`, que `Queue::createPayloadUsing` mete en cada trabajo al
 * despacharlo), nunca de `CurrentCompany`: cuando esto se ejecuta puede que ya no sea la misma.
 *
 * La duración se mide con `JobProcessing` como inicio: sin un instante de arranque no hay duración
 * que anotar, solo el instante final. Se guarda por `getJobId()` porque el mismo listener atiende a
 * (potencialmente) varios trabajos superpuestos en la vida del proceso.
 */
final class QueueEventSubscriber
{
    /** @var array<string, float> jobId => microtime() de cuando empezó */
    private array $inicios = [];

    public function __construct(private readonly MetricsRecorder $metricas) {}

    public function alEmpezar(JobProcessing $evento): void
    {
        $this->inicios[$evento->job->getJobId()] = microtime(true);
    }

    public function alProcesar(JobProcessed $evento): void
    {
        $this->registrar($evento->job, error: false);
    }

    public function alFallar(JobFailed $evento): void
    {
        $this->registrar($evento->job, error: true, excepcion: $evento->exception);
    }

    private function registrar(Job $job, bool $error, ?Throwable $excepcion = null): void
    {
        $jobId = $job->getJobId();
        $inicio = $this->inicios[$jobId] ?? null;
        unset($this->inicios[$jobId]);

        $duracionMs = $inicio !== null ? (microtime(true) - $inicio) * 1000 : 0.0;
        $nombre = class_basename($job->resolveName());
        $companyId = $this->companyIdDelPayload($job);
        $lento = $duracionMs >= (int) config('bmos.monitoreo.colas.lento_segundos', 10) * 1000;

        try {
            $this->metricas->anotar(
                kind: Observation::JOB,
                name: $nombre,
                method: $job->getQueue(),
                module: $this->moduloDeLaClase($job->resolveName()),
                companyId: $companyId,
                durationMs: $duracionMs,
                isWarning: $lento,
                isError: $error,
            );
        } catch (Throwable) {
            // De más, no crítico: ver la cabecera de `DatabaseSink`.
        }

        if ($error) {
            SystemEvent::registrar(
                type: 'queue.failed',
                message: "{$nombre}: falló ({$job->attempts()} ".($job->attempts() === 1 ? 'intento' : 'intentos').')',
                contexto: [
                    'cola' => $job->getQueue(),
                    'intentos' => $job->attempts(),
                    'motivo' => $excepcion !== null ? SecretRedactor::redact($excepcion->getMessage()) : null,
                ],
                level: SystemEvent::GRAVE,
                companyId: $companyId,
            );

            return;
        }

        if ($lento) {
            SystemEvent::registrar(
                type: 'queue.slow',
                message: "{$nombre}: tardó ".round($duracionMs / 1000, 1).' s',
                contexto: ['cola' => $job->getQueue(), 'duracion_ms' => (int) $duracionMs],
                level: SystemEvent::AVISO,
                companyId: $companyId,
            );
        }
    }

    /** La empresa que `Queue::createPayloadUsing` metió en el trabajo al despacharlo, si había una. */
    private function companyIdDelPayload(Job $job): ?int
    {
        $valor = $job->payload()['bmos_company_id'] ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }

    /** «App\Modules\WhatsApp\Jobs\Foo» → «whatsapp». Nulo si no sigue esa convención. */
    private function moduloDeLaClase(string $clase): ?string
    {
        $partes = explode('\\', $clase);

        return ($partes[0] ?? null) === 'App' && ($partes[1] ?? null) === 'Modules' && isset($partes[2])
            ? strtolower($partes[2])
            : null;
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $eventos): array
    {
        return [
            JobProcessing::class => 'alEmpezar',
            JobProcessed::class => 'alProcesar',
            JobFailed::class => 'alFallar',
        ];
    }
}
