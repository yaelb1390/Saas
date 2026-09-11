<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * LA PANTALLA de escaneo masivo, de punta a punta: el formulario que rinde el navegador y el POST que
 * crea las unidades. La aritmética del alta ya la sujeta SerialScanTest; aquí se comprueba que la
 * pantalla la ofrece bien y que respeta los permisos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Serie Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    $this->admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Almacenista',
        'email' => 'admin@serie.test', 'password' => 'secret-password',
    ]), 'admin');

    $this->telefono = Product::create([
        'sku' => 'TEL-1', 'name' => 'Teléfono', 'cost' => '20000', 'price' => '35000',
        'tracks_serials' => true,
    ]);
});

it('la pantalla solo ofrece los productos con serie', function (): void {
    $this->withoutVite();
    // Un consumible, que NO debe aparecer en el selector.
    Product::create(['sku' => 'BAT-1', 'name' => 'Batida', 'cost' => '50', 'price' => '150']);

    $html = $this->actingAs($this->admin)->get(route('panel.serial.scan'))->assertOk()->getContent();

    expect($html)->toContain('Teléfono')
        ->and($html)->not->toContain('Batida');
});

it('sin productos serializados, explica como marcarlos en vez de un desplegable vacio', function (): void {
    $this->withoutVite();
    $this->telefono->update(['tracks_serials' => false]);

    $html = $this->actingAs($this->admin)->get(route('panel.serial.scan'))->assertOk()->getContent();

    expect($html)->toContain('No tienes productos con número de serie');
});

it('el POST da de alta las unidades escaneadas y sube el stock', function (): void {
    $this->actingAs($this->admin)
        ->post(route('panel.products.scan-serials'), [
            'product_id' => $this->telefono->id,
            'warehouse_id' => $this->warehouse->id,
            'condition' => 'nuevo',
            'seriales' => json_encode([['serial' => 'IMEI-100'], ['serial' => 'IMEI-200']]),
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    expect(ProductUnit::count())->toBe(2)
        ->and(ProductUnit::where('serial', 'IMEI-100')->first()->condition)->toBe('nuevo')
        // La invariante también aquí: dos unidades, dos de stock.
        ->and((float) Stock::query()->where('product_id', $this->telefono->id)->value('quantity'))->toBe(2.0);
});

it('no deja escanear contra un producto que no es serializado', function (): void {
    $bateo = Product::create(['sku' => 'BAT-2', 'name' => 'Batida', 'cost' => '50', 'price' => '150']);

    $this->actingAs($this->admin)
        ->post(route('panel.products.scan-serials'), [
            'product_id' => $bateo->id,
            'warehouse_id' => $this->warehouse->id,
            'seriales' => json_encode([['serial' => 'X']]),
        ])
        // Lo para la validación: `tracks_serials = true` es condición del `exists`.
        ->assertSessionHasErrors('product_id');

    expect(ProductUnit::count())->toBe(0);
});

it('no deja dar de alta contra el producto de otra empresa', function (): void {
    $ajena = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($ajena->id);
    $suyo = Product::create(['sku' => 'AJ-1', 'name' => 'Ajeno', 'cost' => '1', 'price' => '2', 'tracks_serials' => true]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->admin)
        ->post(route('panel.products.scan-serials'), [
            'product_id' => $suyo->id,
            'warehouse_id' => $this->warehouse->id,
            'seriales' => json_encode([['serial' => 'X']]),
        ])
        ->assertSessionHasErrors('product_id');

    expect(ProductUnit::count())->toBe(0);
});

it('un cajero no puede escanear: dar existencia es otro permiso', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@serie.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.serial.scan'))->assertForbidden();
});
