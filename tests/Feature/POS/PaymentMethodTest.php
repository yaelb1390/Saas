<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Pagos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@pagos.test', 'password' => 'secret-password',
    ]), 'owner');

    $register = CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01', 'is_active' => true]);
    $this->session = app(CashService::class)->open($register, '1000', $this->owner->id);

    $this->producto = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $this->producto,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '50',
    );
});

/** Cobra un ticket de un producto por la vía indicada. */
function cobrarCon(string $method, string $paid = '100'): void
{
    test()->actingAs(test()->owner)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => test()->producto->id, 'qty' => 1]]),
            'paid' => $paid,
            'payment_method' => $method,
        ])
        ->assertSessionHas('pos_ok');
}

it('una venta en efectivo suma al arqueo de la caja', function (): void {
    cobrarCon('cash');

    $cerrada = app(CashService::class)->close($this->session->fresh(), '1100');

    // Fondo 1000 + 100 cobrados en efectivo, contados 1100: cuadra.
    expect($cerrada->expected_amount)->toBe('1100.00')
        ->and($cerrada->difference)->toBe('0.00');
});

it('una venta con tarjeta NO suma al arqueo de la caja', function (): void {
    cobrarCon('card');

    $cerrada = app(CashService::class)->close($this->session->fresh(), '1000');

    // En el cajón sigue habiendo solo el fondo: el cobro fue al datáfono, no a la gaveta.
    expect($cerrada->expected_amount)->toBe('1000.00')
        ->and($cerrada->difference)->toBe('0.00');
});

it('una venta por transferencia tampoco suma al arqueo', function (): void {
    cobrarCon('transfer');

    $cerrada = app(CashService::class)->close($this->session->fresh(), '1000');

    expect($cerrada->difference)->toBe('0.00');
});

it('la venta guarda la forma de pago elegida', function (): void {
    cobrarCon('card');

    expect(Sale::query()->latest('id')->first()->payment_method)->toBe('card');
});

it('sin forma de pago indicada se asume efectivo', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->producto->id, 'qty' => 1]]),
            'paid' => '100',
        ])
        ->assertSessionHas('pos_ok');

    expect(Sale::query()->latest('id')->first()->payment_method)->toBe('cash');
});

it('rechaza formas de pago que no son de mostrador', function (): void {
    // «credit» existe en el enum (la API lo acepta) pero no es un cobro al contado.
    $this->actingAs($this->owner)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->producto->id, 'qty' => 1]]),
            'paid' => '100',
            'payment_method' => 'credit',
        ])
        ->assertSessionHasErrors('payment_method');
});

it('solo el efectivo se considera dinero en el cajón', function (): void {
    expect(PaymentMethod::Cash->entersCashDrawer())->toBeTrue()
        ->and(PaymentMethod::Card->entersCashDrawer())->toBeFalse()
        ->and(PaymentMethod::Transfer->entersCashDrawer())->toBeFalse();
});

it('los valores del enum siguen siendo los que espera el reporte 606 de la DGII', function (): void {
    // Cambiar estas cadenas rompería DgiiReportService::paymentCode(), que las traduce a los
    // códigos de la columna 23 del formato fiscal.
    expect(PaymentMethod::Cash->value)->toBe('cash')
        ->and(PaymentMethod::Card->value)->toBe('card')
        ->and(PaymentMethod::Transfer->value)->toBe('transfer')
        ->and(PaymentMethod::Check->value)->toBe('check')
        ->and(PaymentMethod::Credit->value)->toBe('credit');
});
