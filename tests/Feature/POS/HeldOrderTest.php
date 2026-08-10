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
use App\Modules\POS\Models\HeldOrder;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Espera Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@espera.test', 'password' => 'secret-password',
    ]), 'owner');

    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->owner->id);

    $this->cono = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $this->cono,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '50',
    );
});

/** Aparca un pedido de la cantidad indicada y devuelve la respuesta. */
function aparcar(int $qty = 2): TestResponse
{
    return test()->actingAs(test()->owner)->postJson(route('panel.quick-pos.held.store'), [
        'cart' => [['id' => test()->cono->id, 'qty' => $qty, 'options' => []]],
    ]);
}

it('aparca el pedido y le da una referencia corta', function (): void {
    $res = aparcar()->assertCreated();

    expect($res->json('reference'))->toBe('E-01')
        ->and($res->json('pending'))->toHaveCount(1)
        ->and($res->json('pending.0.total'))->toBe('200.00')
        ->and($res->json('pending.0.items'))->toBe(1);
});

it('aparcar NO descuenta stock', function (): void {
    // Reservar mercancía por un pedido que quizá nunca se cobre la dejaría bloqueada sin que nadie
    // sepa por qué. El stock se mueve al cobrar, no antes.
    aparcar()->assertCreated();

    expect($this->cono->fresh()->totalStock())->toBe('50.000');
});

it('aparcar no crea ninguna venta', function (): void {
    // Una venta en borrador consumiria un numero («V-000001») antes de cobrar y dejaria huecos en
    // listados y reportes.
    aparcar()->assertCreated();

    expect(Sale::query()->count())->toBe(0);
});

it('numera los pedidos del día de forma correlativa', function (): void {
    expect(aparcar()->json('reference'))->toBe('E-01')
        ->and(aparcar()->json('reference'))->toBe('E-02')
        ->and(aparcar()->json('reference'))->toBe('E-03');
});

it('recupera el pedido con su contenido', function (): void {
    aparcar(3);
    $held = HeldOrder::query()->latest('id')->first();

    $carrito = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.held.resume', $held))->assertOk()->json('cart');

    expect($carrito)->toHaveCount(1)
        ->and($carrito[0]['id'])->toBe($this->cono->id)
        ->and($carrito[0]['qty'])->toBe(3)
        ->and($carrito[0]['name'])->toBe('Cono');
});

it('al recuperarlo usa el precio de HOY, no el del momento en que se aparcó', function (): void {
    aparcar(1);
    $held = HeldOrder::query()->latest('id')->first();

    // Sube la tarifa mientras el pedido esperaba.
    $this->cono->update(['price' => '150']);

    $carrito = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.held.resume', $held))->assertOk()->json('cart');

    // JSON devuelve 150 sin decimales; se compara el valor, no su tipo.
    expect((float) $carrito[0]['price'])->toEqual(150.0);
});

it('omite del pedido un producto descatalogado en vez de romper la recuperación', function (): void {
    aparcar(1);
    $held = HeldOrder::query()->latest('id')->first();

    $this->cono->update(['is_active' => false]);

    $carrito = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.held.resume', $held))->assertOk()->json('cart');

    expect($carrito)->toBe([]);
});

it('descartar lo saca de la lista', function (): void {
    aparcar();
    $held = HeldOrder::query()->latest('id')->first();

    $this->actingAs($this->owner)
        ->deleteJson(route('panel.quick-pos.held.destroy', $held))
        ->assertOk()
        ->assertJsonCount(0, 'pending');

    expect(HeldOrder::query()->count())->toBe(0);
});

it('el stock se descuenta al cobrar el pedido recuperado, no antes', function (): void {
    aparcar(2)->assertCreated();
    expect($this->cono->fresh()->totalStock())->toBe('50.000');

    $this->actingAs($this->owner)->postJson(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $this->cono->id, 'qty' => 2]]),
        'paid' => '500',
    ])->assertOk();

    expect($this->cono->fresh()->totalStock())->toBe('48.000');
});

it('rechaza aparcar un carrito vacío', function (): void {
    $this->actingAs($this->owner)
        ->postJson(route('panel.quick-pos.held.store'), ['cart' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cart');
});

it('no expone ni deja descartar un pedido de otra empresa', function (): void {
    aparcar();
    $propio = HeldOrder::query()->latest('id')->first();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'ajeno@espera.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)->getJson(route('panel.quick-pos.held.resume', $propio))->assertNotFound();
    $this->actingAs($ajeno)->deleteJson(route('panel.quick-pos.held.destroy', $propio))->assertNotFound();

    expect(HeldOrder::withoutCompanyScope()->count())->toBe(1);
});

it('la lista solo trae los pedidos de la empresa activa', function (): void {
    aparcar();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena 2'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Ajeno',
        'email' => 'ajeno2@espera.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($ajeno)
        ->getJson(route('panel.quick-pos.held.index'))
        ->assertOk()
        ->assertJsonCount(0, 'pending');
});

it('el cajero en modo kiosco puede aparcar y recuperar', function (): void {
    // Sin esto, la funcion seria inutil justo para quien mas la necesita.
    $this->company->update(['settings' => ['pos' => ['kiosk_roles' => ['staff']]]]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@espera.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->postJson(route('panel.quick-pos.held.store'), [
        'cart' => [['id' => $this->cono->id, 'qty' => 1, 'options' => []]],
    ])->assertCreated();

    $held = HeldOrder::query()->latest('id')->first();

    $this->actingAs($cajero)->getJson(route('panel.quick-pos.held.resume', $held))->assertOk();
});
