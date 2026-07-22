<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Delivery Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->delivery = app(DeliveryService::class);
});

it('crea, asigna y entrega despachando el evento', function (): void {
    $delivery = $this->delivery->create('Calle Demo #1', 'Cliente X', '18095550000');

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->code)->toBe('ENV-000001');

    $this->delivery->assign($delivery, 'Repartidor 1');
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Assigned)
        ->and($delivery->driver_name)->toBe('Repartidor 1');

    Event::fake([DeliveryStatusChanged::class]);

    $this->delivery->transition($delivery, DeliveryStatus::Delivered);
    expect($delivery->refresh()->status)->toBe(DeliveryStatus::Delivered)
        ->and($delivery->delivered_at)->not->toBeNull();

    Event::assertDispatched(DeliveryStatusChanged::class);
});

it('aísla las entregas por empresa', function (): void {
    $this->delivery->create('Calle Demo #1');

    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Delivery'));
    app(CurrentCompany::class)->set($other->id);

    expect(Delivery::count())->toBe(0);
});
