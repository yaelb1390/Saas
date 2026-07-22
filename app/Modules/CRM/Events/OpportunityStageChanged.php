<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Modules\CRM\Models\Opportunity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando una oportunidad cambia de etapa (o se gana/pierde). Punto de enganche para
 * automatizaciones (n8n), notificaciones por WhatsApp y reportes de embudo.
 */
final class OpportunityStageChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Opportunity $opportunity,
        public readonly int $fromStageId,
        public readonly int $toStageId,
    ) {}
}
