<?php

declare(strict_types=1);

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Exceptions\CashSessionException;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cash Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->register = CashRegister::create(['name' => 'Caja 1']);
    $this->cash = app(CashService::class);
});

it('abre una sesión y no permite dos abiertas en la misma caja', function (): void {
    $session = $this->cash->open($this->register, '1000');

    expect($session->isOpen())->toBeTrue()
        ->and($session->opening_amount)->toBe('1000.00');

    expect(fn () => $this->cash->open($this->register, '500'))
        ->toThrow(CashSessionException::class);
});

it('calcula el arqueo al cerrar (esperado = fondo + movimientos)', function (): void {
    $session = $this->cash->open($this->register, '1000');
    $this->cash->registerMovement($session, CashMovementType::Sale, '250');
    $this->cash->registerMovement($session, CashMovementType::Expense, '100');

    $this->cash->close($session, '1150');
    $session->refresh();

    expect($session->status)->toBe(CashSessionStatus::Closed)
        ->and($session->expected_amount)->toBe('1150.00')
        ->and($session->counted_amount)->toBe('1150.00')
        ->and($session->difference)->toBe('0.00');
});

it('refleja faltante en la diferencia', function (): void {
    $session = $this->cash->open($this->register, '0');
    $this->cash->registerMovement($session, CashMovementType::Sale, '100');

    $this->cash->close($session, '90');

    expect($session->refresh()->difference)->toBe('-10.00');
});

it('no permite movimientos en una sesión cerrada', function (): void {
    $session = $this->cash->open($this->register, '0');
    $this->cash->close($session, '0');

    expect(fn () => $this->cash->registerMovement($session->refresh(), CashMovementType::Income, '50'))
        ->toThrow(CashSessionException::class);
});
