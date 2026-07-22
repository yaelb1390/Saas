<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Mostrador Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'FIL-1', 'name' => 'Filtro', 'cost' => '50', 'price' => '250']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');
});

function ncfSequence(NcfType $type = NcfType::Consumo): FiscalSequence
{
    return FiscalSequence::create([
        'type' => $type, 'next_number' => 1, 'range_from' => 1,
        'range_to' => 1000, 'number_length' => 8, 'is_active' => true,
    ]);
}

function counterCart(int $productId, int $qty = 1): string
{
    return json_encode([['id' => $productId, 'qty' => $qty]]);
}

it('factura desde el mostrador: crea la venta, descuenta stock y emite NCF', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 2),
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok')
        ->assertSessionHas('pos_receipt_id');

    $invoice = Invoice::first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->ncf)->toBe('B0200000001')
        ->and(Sale::count())->toBe(1)
        ->and((float) $this->product->fresh()->totalStock())->toBe(8.0); // 10 - 2
});

it('stock insuficiente revierte todo: ni venta ni factura', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 99), // solo hay 10
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and((float) $this->product->fresh()->totalStock())->toBe(10.0);
});

it('sin secuencia de NCF activa revierte la venta', function (): void {
    // No se crea ninguna FiscalSequence.
    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 1),
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and((float) $this->product->fresh()->totalStock())->toBe(10.0); // stock intacto
});

it('crédito fiscal sin RNC revierte (la DGII exige identificar al cliente)', function (): void {
    ncfSequence(NcfType::CreditoFiscal);

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 1),
            'type' => 'B01', 'paid' => '100000', // B01 exige customer_tax_id
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('el ticket vacío no factura', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), ['cart' => '[]', 'type' => 'B02', 'paid' => '0'])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Invoice::count())->toBe(0);
});

it('un usuario sin permiso invoices.issue no accede al mostrador', function (): void {
    $noPerm = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'noperm@mostrador.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($noPerm)->get(route('panel.parts'))->assertForbidden();
    $this->actingAs($noPerm)
        ->post(route('panel.parts.invoice'), ['cart' => counterCart($this->product->id), 'type' => 'B02', 'paid' => '250'])
        ->assertForbidden();
});

it('la búsqueda del mostrador devuelve la pieza en JSON', function (): void {
    $this->actingAs($this->owner)
        ->getJson(route('panel.parts.search', ['q' => 'filtro']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Filtro');
});
