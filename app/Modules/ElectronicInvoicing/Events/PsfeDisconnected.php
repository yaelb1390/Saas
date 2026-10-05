<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Una empresa desconectó su proveedor certificado (PSFE). `emissionStopped` = se apagó la emisión. */
final class PsfeDisconnected implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $companyId,
        public readonly string $psfe,
        public readonly bool $emissionStopped,
        public readonly ?int $userId,
    ) {}
}
