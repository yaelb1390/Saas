<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

/**
 * Respuesta normalizada de un proveedor. `raw` es la respuesta original (sin credenciales) para
 * guardarla tal cual; `sequenceUsed` es el `secuenciaUtilizada` de la DGII [DT p.24]: false = el
 * e-NCF puede reutilizarse.
 *
 * @param  list<array{codigo: string|int|null, valor: string|null}>  $messages
 */
final readonly class ProviderResult
{
    /**
     * @param  list<array{codigo: string|int|null, valor: string|null}>  $messages
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
}
