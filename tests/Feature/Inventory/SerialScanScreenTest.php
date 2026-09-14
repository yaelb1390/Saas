<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\SerialScanService;
use App\Modules\Inventory\Support\ProductLookupPresenter;
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

it('la pantalla ofrece cualquier producto, marcando los que ya llevan serie', function (): void {
    $this->withoutVite();
    // Un consumible: ahora TAMBIEN aparece, porque se puede serializar al escanear.
    Product::create(['sku' => 'BAT-1', 'name' => 'Batida', 'cost' => '50', 'price' => '150']);

    $html = $this->actingAs($this->admin)->get(route('panel.serial.scan'))->assertOk()->getContent();

    expect($html)->toContain('Teléfono')
        ->and($html)->toContain('Batida')
        // El que no lleva serie se distingue: se serializa al escanearle la primera.
        ->and($html)->toContain('se serializa al escanear');
});

it('sin ningun producto, invita a crear uno', function (): void {
    $this->withoutVite();
    // Se quitan todos los productos de la empresa.
    Product::query()->delete();

    $html = $this->actingAs($this->admin)->get(route('panel.serial.scan'))->assertOk()->getContent();

    expect($html)->toContain('Todavía no tienes productos');
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

it('escanear un producto sin marcar lo serializa al vuelo', function (): void {
    $generico = Product::create(['sku' => 'GEN-2', 'name' => 'Genérico', 'cost' => '50', 'price' => '150']);

    $this->actingAs($this->admin)
        ->post(route('panel.products.scan-serials'), [
            'product_id' => $generico->id,
            'warehouse_id' => $this->warehouse->id,
            'seriales' => json_encode([['serial' => 'SN-X']]),
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok');

    expect(ProductUnit::count())->toBe(1)
        ->and($generico->fresh()->tracks_serials)->toBeTrue();
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

// ------------------------------------------------------------------ El selector de unidad del POS

/*
 * EL ENDPOINT QUE ALIMENTA EL SELECTOR del terminal: las unidades disponibles de un producto
 * serializado. Solo las de la empresa y en estado disponible; nunca una ya vendida, que llevaría al
 * cajero a intentar vender algo que no está.
 */
it('lista las unidades disponibles con su serie y su precio', function (): void {
    app(SerialScanService::class)->alta($this->telefono, $this->warehouse, [
        new ScanUnitData('IMEI-A', condition: 'nuevo', price: '35000'),
        new ScanUnitData('IMEI-B', condition: 'usado', price: '22000'),
    ]);

    // Se vende una: no debe aparecer en la lista.
    ProductUnit::where('serial', 'IMEI-B')->update(['status' => ProductUnit::VENDIDA]);

    $res = $this->actingAs($this->admin)
        ->getJson(route('panel.products.units', $this->telefono))
        ->assertOk()
        ->json('units');

    expect($res)->toHaveCount(1)
        ->and($res[0]['serial'])->toBe('IMEI-A')
        ->and($res[0]['price'])->toBe('35000.00');
});

it('el buscador dice si un producto se vende por serie', function (): void {
    $payload = app(ProductLookupPresenter::class)
        ->payload($this->telefono->sku);

    expect($payload['found'])->toBeTrue()
        ->and($payload['product']['serializado'])->toBeTrue();
});
