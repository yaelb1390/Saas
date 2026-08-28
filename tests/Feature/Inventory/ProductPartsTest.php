<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Repuestos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@repuestos.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('guarda los campos de repuesto al crear un producto', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), [
            'sku' => 'FIL-001', 'name' => 'Filtro de aceite', 'price' => '250',
            'part_number' => '90915-YZZE1', 'brand' => 'Toyota',
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla',
            'year_from' => '2015', 'year_to' => '2020', 'location' => 'Pasillo 3 / Est. B',
        ])
        ->assertRedirect();

    $product = Product::firstWhere('sku', 'FIL-001');

    expect($product->part_number)->toBe('90915-YZZE1')
        ->and($product->brand)->toBe('Toyota')
        ->and($product->vehicle_make)->toBe('Toyota')
        ->and($product->year_from)->toBe(2015)
        ->and($product->year_to)->toBe(2020)
        ->and($product->location)->toBe('Pasillo 3 / Est. B')
        ->and($product->vehicleFit())->toBe('Toyota Corolla 2015-2020');
});

it('rechaza año hasta menor que año desde', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), [
            'sku' => 'X1', 'name' => 'Pieza', 'year_from' => '2020', 'year_to' => '2010',
        ])
        ->assertSessionHasErrors('year_to');
});

it('los datos del VEHÍCULO solo salen en el perfil repuestos', function (): void {
    /*
     * Este test comprobaba que TODOS los detalles estuvieran escondidos salvo en repuestos, y ya no
     * es así: marca, estante, referencia y descripción se piden a cualquier negocio que no sea de
     * comida —una ferretería quiere apuntar en qué estante está un tubo—.
     *
     * Lo que sigue siendo exclusivo de repuestos es el VEHÍCULO: «Marca del vehículo» en una tienda
     * de ropa es un campo que nadie va a rellenar nunca.
     */
    $this->actingAs($this->owner)
        ->get(route('panel.products'))
        ->assertOk()
        ->assertDontSee('name="vehicle_make"', false)
        // Y los demás detalles SÍ, aunque el perfil sea el general.
        ->assertSee('name="brand"', false);

    $this->company->update(['settings' => ['pos' => ['profile' => 'repuestos', 'options' => []]]]);

    $this->actingAs($this->owner)
        ->get(route('panel.products'))
        ->assertOk()
        ->assertSee('name="vehicle_make"', false);
});

it('la búsqueda difusa encuentra por nº de parte, marca y vehículo', function (): void {
    Product::create([
        'sku' => 'FIL-001', 'name' => 'Filtro de aceite', 'price' => '250',
        'part_number' => '90915-YZZE1', 'brand' => 'Bosch',
        'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla',
    ]);
    Product::create(['sku' => 'BUJ-001', 'name' => 'Bujía NGK', 'price' => '150', 'brand' => 'NGK']);

    $presenter = app(ProductLookupPresenter::class);

    expect($presenter->search('corolla'))->toHaveCount(1)
        ->and($presenter->search('90915')[0]['name'])->toBe('Filtro de aceite')
        ->and($presenter->search('bosch')[0]['sku'])->toBe('FIL-001')
        ->and($presenter->search('ngk')[0]['name'])->toBe('Bujía NGK')
        ->and($presenter->search('zzzzz'))->toBe([]);
});

it('el payload de búsqueda incluye los datos de la pieza', function (): void {
    Product::create([
        'sku' => 'FIL-001', 'name' => 'Filtro de aceite', 'price' => '250',
        'part_number' => '90915-YZZE1', 'brand' => 'Bosch',
        'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla',
        'year_from' => 2015, 'year_to' => 2020, 'location' => 'Est. B',
    ]);

    $row = app(ProductLookupPresenter::class)->search('filtro')[0];

    expect($row['part_number'])->toBe('90915-YZZE1')
        ->and($row['brand'])->toBe('Bosch')
        ->and($row['vehicle'])->toBe('Toyota Corolla 2015-2020')
        ->and($row['location'])->toBe('Est. B');
});
