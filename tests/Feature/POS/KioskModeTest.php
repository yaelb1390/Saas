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
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Kiosco Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@kiosco.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@kiosco.test', 'password' => 'secret-password',
    ]), 'staff');

    // El modo kiosco viene APAGADO: cada empresa lo enciende a propósito. Aquí se enciende para el
    // rol de cajero, que es el caso de uso previsto.
    $this->company->update(['settings' => ['pos' => ['kiosk_roles' => ['staff']]]]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);
});

it('con el modo apagado el cajero navega con normalidad', function (): void {
    $this->company->update(['settings' => null]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);

    // Es el comportamiento de todas las empresas que no lo pidan: nada cambia para ellas.
    $this->actingAs($this->cajero)->get(route('panel.pos'))->assertOk();
});

it('lleva al cajero al terminal de venta desde cualquier pantalla del panel', function (string $ruta): void {
    $this->actingAs($this->cajero)->get($ruta)
        ->assertRedirect(route('panel.quick-pos.index'));
})->with([
    '/dashboard',
    '/panel/inventario',
    '/panel/ventas',
    '/panel/crm',
    '/panel/pos',
]);

it('no entra en bucle: el propio terminal responde 200', function (): void {
    $this->actingAs($this->cajero)->get(route('panel.quick-pos.index'))->assertOk();
});

it('el cajero puede cerrar sesión, o quedaría atrapado sin más salida que borrar cookies', function (): void {
    $this->actingAs($this->cajero)->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});

it('el cajero puede abrir la caja y cobrar', function (): void {
    $this->actingAs($this->cajero)
        ->post(route('panel.pos.open'), ['opening_amount' => '1000'])
        ->assertRedirect();

    $producto = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $producto,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '10',
    );

    $this->actingAs($this->cajero)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $producto->id, 'qty' => 1]]),
            'paid' => '100',
        ])
        ->assertSessionHas('pos_ok');
});

it('el cajero puede cerrar la caja al acabar el turno', function (): void {
    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->cajero->id);

    $this->actingAs($this->cajero)
        ->post(route('panel.pos.close'), ['counted_amount' => '1000'])
        ->assertRedirect();
});

it('el cajero recibe las fotos del catálogo, o la rejilla saldría vacía', function (): void {
    $producto = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);

    // Sin foto el endpoint devuelve 404, pero lo que se comprueba aquí es que NO lo intercepta el
    // kiosco con una redirección: un 404 significa que la ruta le está permitida.
    $this->actingAs($this->cajero)
        ->get(route('panel.products.image', $producto))
        ->assertNotFound();
});

it('el cajero puede ver y reimprimir el recibo de una venta', function (): void {
    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->cajero->id);

    $producto = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $producto,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '10',
    );

    $this->actingAs($this->cajero)->post(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $producto->id, 'qty' => 1]]),
        'paid' => '100',
    ]);

    $sale = Sale::query()->latest('id')->first();

    $this->actingAs($this->cajero)->get(route('panel.sales.receipt', $sale))->assertOk();
});

it('el dueño navega por todo el panel sin verse afectado', function (string $ruta): void {
    $this->actingAs($this->owner)->get($ruta)->assertOk();
})->with([
    '/dashboard',
    '/panel/inventario',
    '/panel/ventas',
]);

it('a una petición de datos se le responde 403, no una redirección a HTML', function (): void {
    $this->actingAs($this->cajero)
        ->getJson('/panel/ventas')
        ->assertForbidden();
});

it('el cobro por fetch devuelve JSON con el código de venta y no una redirección', function (): void {
    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->cajero->id);

    $producto = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $producto,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '10',
    );

    $json = $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $producto->id, 'qty' => 1]]),
            'paid' => '500',
        ])
        ->assertOk()
        ->json();

    expect($json['code'])->toStartWith('V-')
        ->and($json['change'])->toBe('400.00')
        ->and($json)->toHaveKey('receipt_url');
});

it('un cobro imposible responde 422 con el motivo, no un error de servidor', function (): void {
    // Sin caja abierta: es una regla de negocio, no una avería.
    $this->actingAs($this->cajero)
        ->postJson(route('panel.pos.checkout'), ['cart' => '[]', 'paid' => '100'])
        ->assertStatus(422)
        ->assertJson(['message' => 'No hay una caja abierta.']);
});

it('el super administrador nunca queda encerrado', function (): void {
    $super = User::create([
        'company_id' => $this->company->id, 'name' => 'Operador',
        'email' => 'super@kiosco.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);

    $this->actingAs($super)->get('/dashboard')->assertOk();
});

it('la empresa elige qué roles quedan encerrados', function (): void {
    // Se encierra al administrador en vez de al cajero.
    $this->company->update(['settings' => ['pos' => ['kiosk_roles' => ['admin']]]]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);

    $admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin',
        'email' => 'admin@kiosco.test', 'password' => 'secret-password',
    ]), 'admin');

    $this->actingAs($admin)->get('/dashboard')->assertRedirect(route('panel.quick-pos.index'));
    $this->actingAs($this->cajero)->get('/dashboard')->assertOk();
});
