<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Events\PurchaseOrderReceived;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Compras Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->supplier = Supplier::create(['name' => 'Proveedor Uno', 'is_active' => true]);
    $this->product = Product::create(['sku' => 'CMP-1', 'name' => 'Producto Compra', 'cost' => '10', 'price' => '25']);

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Comprador',
        'email' => 'admin@compras.test', 'password' => 'secret-password',
    ]), 'admin');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderPayload(array $overrides = []): array
{
    return array_merge([
        'supplier_id' => test()->supplier->id,
        'warehouse_id' => test()->warehouse->id,
        'lines' => [
            ['product_id' => test()->product->id, 'quantity' => '10', 'unit_cost' => '10'],
        ],
        'tax' => '0',
    ], $overrides);
}

// ---------------------------------------------------------------- Crear

it('crea una orden de compra desde el panel', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload())
        ->assertRedirect();

    $order = PurchaseOrder::firstOrFail();

    expect($order->code)->toBe('PO-000001')
        ->and($order->status)->toBe(PurchaseOrderStatus::Ordered)
        ->and($order->total)->toBe('100.00')
        ->and($order->items()->count())->toBe(1);
});

it('suma el impuesto al total', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload(['tax' => '18']))
        ->assertRedirect();

    expect(PurchaseOrder::firstOrFail()->total)->toBe('118.00');
});

it('crear la orden todavía no toca el stock', function (): void {
    // La existencia entra al recibir, no al pedir: la mercancía aún no ha llegado.
    $this->actingAs($this->admin)->post(route('panel.purchase-orders.store'), orderPayload())->assertRedirect();

    expect(Stock::where('product_id', $this->product->id)->exists())->toBeFalse();
});

it('rechaza una orden sin líneas', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload(['lines' => []]))
        ->assertSessionHasErrors('lines');

    expect(PurchaseOrder::count())->toBe(0);
});

it('rechaza una cantidad de cero', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload([
            'lines' => [['product_id' => $this->product->id, 'quantity' => '0', 'unit_cost' => '10']],
        ]))
        ->assertSessionHasErrors('lines.0.quantity');
});

// ---------------------------------------------------------------- Recibir

it('recibir la orden mete la mercancía en el almacén', function (): void {
    Event::fake([PurchaseOrderReceived::class]);

    $this->actingAs($this->admin)->post(route('panel.purchase-orders.store'), orderPayload())->assertRedirect();
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.receive', $order))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($order->received_at)->not->toBeNull()
        ->and(Stock::where('product_id', $this->product->id)->firstOrFail()->quantity)->toBe('10.000')
        ->and($order->items()->first()->received_quantity)->toBe('10.000');

    Event::assertDispatched(PurchaseOrderReceived::class);
});

it('recibir dos veces no duplica la existencia', function (): void {
    // Regresión crítica: un doble clic o un F5 no pueden inflar el inventario.
    $this->actingAs($this->admin)->post(route('panel.purchase-orders.store'), orderPayload())->assertRedirect();
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->admin)->post(route('panel.purchase-orders.receive', $order))->assertRedirect();

    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.receive', $order))
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Stock::where('product_id', $this->product->id)->firstOrFail()->quantity)->toBe('10.000');
});

// ---------------------------------------------------------------- Aislamiento multiempresa

it('rechaza una línea con un producto de otra empresa', function (): void {
    // Sin el «exists» acotado del Form Request, el servicio insertaría la línea sin comprobar nada
    // y al recibir se sumaría existencia al producto de la otra empresa.
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['sku' => 'AJ-1', 'name' => 'Ajeno', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload([
            'lines' => [['product_id' => $ajeno->id, 'quantity' => '10', 'unit_cost' => '10']],
        ]))
        ->assertSessionHasErrors('lines.0.product_id');

    expect(PurchaseOrder::withoutCompanyScope()->count())->toBe(0);
});

it('rechaza un proveedor de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Dos'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Supplier::create(['name' => 'Proveedor Ajeno']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.store'), orderPayload(['supplier_id' => $ajeno->id]))
        ->assertSessionHasErrors('supplier_id');
});

it('no se puede recibir una orden de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Tres'));
    app(CurrentCompany::class)->set($otra->id);
    $ajena = PurchaseOrder::create([
        'supplier_id' => Supplier::create(['name' => 'Prov Ajeno'])->id,
        'warehouse_id' => $otra->warehouses()->where('is_default', true)->firstOrFail()->id,
        'code' => 'PO-999999', 'status' => PurchaseOrderStatus::Ordered, 'total' => '50',
    ]);
    app(CurrentCompany::class)->set($this->company->id);

    // El route model binding la resuelve bajo el CompanyScope: desde aquí no existe.
    $this->actingAs($this->admin)
        ->post(route('panel.purchase-orders.receive', $ajena))
        ->assertNotFound();
});

// ---------------------------------------------------------------- Permisos

it('el cajero ve la pantalla pero no crea ni recibe órdenes', function (): void {
    // «staff» tiene purchases.view, así que entra a Compras; pero no purchases.manage/receive.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@compras.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.purchases'))->assertOk();

    $this->actingAs($cajero)
        ->post(route('panel.purchase-orders.store'), orderPayload())
        ->assertForbidden();

    $this->actingAs($this->admin)->post(route('panel.purchase-orders.store'), orderPayload())->assertRedirect();
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($cajero)
        ->post(route('panel.purchase-orders.receive', $order))
        ->assertForbidden();
});

it('la pantalla ofrece crear y recibir a quien tiene permiso', function (): void {
    $this->actingAs($this->admin)->post(route('panel.purchase-orders.store'), orderPayload())->assertRedirect();
    $order = PurchaseOrder::firstOrFail();

    $this->actingAs($this->admin)
        ->get(route('panel.purchases'))
        ->assertOk()
        ->assertSee(route('panel.purchase-orders.store'))
        ->assertSee(route('panel.purchase-orders.receive', $order));
});
