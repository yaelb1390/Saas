<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un e-CF salió por un proveedor de RESPALDO porque los anteriores fallaron. Para avisar al dueño y a
 * n8n de que su proveedor principal tiene problemas. Solo datos, nunca credenciales.
 *
 * @param  list<string>  $failedProviders  los conectores que fallaron antes, en orden
 */
final class PsfeFailover implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $failedProviders
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $electronicInvoiceId,
        public readonly string $encf,
        public readonly array $failedProviders,
        public readonly string $usedProvider,
    ) {}
}
