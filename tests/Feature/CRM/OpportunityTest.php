<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\DTOs\CreateCustomerData;
use App\Modules\CRM\Enums\OpportunityStatus;
use App\Modules\CRM\Events\OpportunityStageChanged;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Services\CrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'CRM Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->pipeline = Pipeline::where('is_default', true)->firstOrFail();
    $this->crm = app(CrmService::class);
});

it('provisiona un pipeline por defecto con sus etapas al crear la empresa', function (): void {
    expect($this->pipeline->is_default)->toBeTrue()
        ->and($this->pipeline->stages()->count())->toBe(5);
});

it('abre una oportunidad en la primera etapa', function (): void {
    $opportunity = $this->crm->openOpportunity($this->pipeline, 'Negocio 1', '1000');

    expect($opportunity->stage_id)->toBe($this->pipeline->stages()->first()->id)
        ->and($opportunity->status)->toBe(OpportunityStatus::Open);
});

it('marca la oportunidad como ganada al mover a una etapa terminal', function (): void {
    $opportunity = $this->crm->openOpportunity($this->pipeline, 'Negocio 2', '2000');
    $wonStage = $this->pipeline->stages()->where('is_won', true)->firstOrFail();

    Event::fake([OpportunityStageChanged::class]);

    $this->crm->moveToStage($opportunity, $wonStage);
    $opportunity->refresh();

    expect($opportunity->status)->toBe(OpportunityStatus::Won)
        ->and($opportunity->closed_at)->not->toBeNull();

    Event::assertDispatched(OpportunityStageChanged::class);
});

it('aísla clientes y oportunidades por empresa', function (): void {
    $this->crm->createCustomer(new CreateCustomerData(name: 'Cliente A'));

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra CRM'));
    app(CurrentCompany::class)->set($other->id);

    expect(Customer::count())->toBe(0);
});
