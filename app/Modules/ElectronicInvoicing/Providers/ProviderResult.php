<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

/**
 * Respuesta normalizada de un proveedor. `raw` es la respuesta original (sin credenciales) para
 * guardarla tal cual; `sequenceUsed` es el `secuenciaUtilizada` de la DGII [DT p.24]: false = el
 * e-NCF puede reutilizarse.
 *
 * Lo que añaden los proveedores certificados (PSFE) con respaldo:
 *   · `delivered`: false = el documento NO llegó a salir hacia el proveedor (no se pudo conectar,
 *     credenciales rechazadas al pedir el token…), así que probar con otro no puede duplicarlo.
 *     null = no se sabe (tiempo agotado, 5xx): antes de usar otro hay que preguntarle a este.
 *   · `signedXml`: el XML que firmó el propio proveedor (los que firman y envían en una llamada).
 *   · `via`: el conector que lo atendió; `notes`: lo que pasó con los anteriores, para la bitácora;
 *     `failoverFrom`: los conectores que fallaron antes del que lo envió.
 *
 * @param  list<array{codigo: string|int|null, valor: string|null}>  $messages
 */
final readonly class ProviderResult
{
    /**
     * @param  list<array{codigo: string|int|null, valor: string|null}>  $messages
     * @param  list<string>  $notes
     * @param  list<string>  $failoverFrom
     */
    public function __construct(
        public ProviderOutcome $outcome,
        public ?string $trackId = null,
        public ?string $code = null,
        public ?string $status = null,
        public array $messages = [],
        public ?bool $sequenceUsed = null,
        public ?int $httpStatus = null,
        public ?string $raw = null,
        public ?string $error = null,
        public ?bool $delivered = null,
        public ?string $signedXml = null,
        public ?string $via = null,
        public array $notes = [],
        public array $failoverFrom = [],
    ) {}

    /** Primer mensaje legible para enseñar al usuario. */
    public function summary(): string
    {
        foreach ($this->messages as $m) {
            if (($m['valor'] ?? '') !== '') {
                return (string) $m['valor'];
            }
        }

        return $this->error ?? $this->status ?? $this->outcome->value;
    }

    /**
     * La misma respuesta, apuntando qué conector la dio y qué pasó antes con los otros.
     *
     * @param  list<string>  $notes
     * @param  list<string>  $failoverFrom
     */
    public function via(string $via, array $notes = [], array $failoverFrom = []): self
    {
        return new self(
            $this->outcome, $this->trackId, $this->code, $this->status, $this->messages, $this->sequenceUsed,
            $this->httpStatus, $this->raw, $this->error, $this->delivered, $this->signedXml,
            $via, [...$notes, ...$this->notes], $failoverFrom,
        );
    }
}
