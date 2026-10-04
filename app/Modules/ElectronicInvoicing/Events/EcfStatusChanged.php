<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Events;

use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un e-CF cambió de estado. Es el gancho para todo lo que reacciona a la DGII: avisos al dueño,
 * n8n/webhooks, el CRM… (CLAUDE.md: «toda acción importante debe disparar eventos»).
 *
 * Después de confirmar la transacción: un cambio que se revierte no avisa a nadie.
 * Lleva solo datos (no el modelo): los oyentes leen lo que necesiten.
 */
final class EcfStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $electronicInvoiceId,
        public readonly int $companyId,
        public readonly string $encf,
        public readonly ?EcfStatus $from,
        public readonly EcfStatus $to,
        public readonly Environment $environment,
    ) {}
}
