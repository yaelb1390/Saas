<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Events;

use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Una empresa conectó su proveedor certificado (PSFE). Solo datos, NUNCA credenciales: los oyentes
 * (avisos, n8n, auditoría) no tienen por qué ver una clave.
 */
final class PsfeConnected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly string $psfe,
        public readonly Environment $environment,
        public readonly ?int $userId,
    ) {}
}
