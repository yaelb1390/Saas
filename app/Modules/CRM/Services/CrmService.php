<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Enums\OpportunityStatus;
use App\Modules\CRM\Events\OpportunityStageChanged;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use RuntimeException;

/**
 * Lógica de negocio del CRM: alta de clientes y gestión de oportunidades por el pipeline.
 */
final class CrmService
{
    public function createCustomer(CreateCustomerData $data): Customer
    {
        return Customer::create($data->toAttributes());
    }

    /**
     * Abre una oportunidad en la primera etapa del pipeline.
     */
    public function openOpportunity(
        Pipeline $pipeline,
        string $title,
        string $amount = '0',
        ?Customer $customer = null,
    ): Opportunity {
        $firstStage = $pipeline->stages()->first();

        if ($firstStage === null) {
            throw new RuntimeException("El pipeline {$pipeline->id} no tiene etapas.");
        }

        return Opportunity::create([
            'company_id' => $pipeline->company_id,
            'customer_id' => $customer?->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $firstStage->id,
            'title' => $title,
            'amount' => $amount,
            'status' => OpportunityStatus::Open,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Mueve la oportunidad a una etapa; ajusta el estado si la etapa es terminal (ganada/perdida).
     */
    public function moveToStage(Opportunity $opportunity, PipelineStage $stage): Opportunity
    {
        $fromStageId = (int) $opportunity->stage_id;

        $status = match (true) {
            $stage->is_won => OpportunityStatus::Won,
            $stage->is_lost => OpportunityStatus::Lost,
            default => OpportunityStatus::Open,
        };

        $opportunity->update([
            'stage_id' => $stage->id,
            'status' => $status,
            'closed_at' => $status->isClosed() ? now() : null,
        ]);

        OpportunityStageChanged::dispatch($opportunity, $fromStageId, (int) $stage->id);

        return $opportunity;
    }
}
