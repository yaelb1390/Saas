<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\POS\Support\PosProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Poder escribir los detalles del artículo, y decir en qué almacén entra.
 *
 * Dos huecos que venían de lo mismo: campos que EXISTEN en la base y que el mostrador ya enseña
 * —marca, estante, número de parte, descripción— pero que no se podían teclear desde ninguna
 * pantalla salvo con el perfil «repuestos». Los productos se daban de alta sin esos datos porque no
 * había dónde ponerlos, no porque no hicieran falta.
 *
 * Y el stock inicial entraba SIEMPRE en el almacén de por omisión, escrito a fuego: dabas de alta
 * cien piezas para la sucursal y aparecían en el principal.
 */

uses(RefreshDatabase::class);

/** Pone el tipo de negocio de la empresa activa. */
function conPerfil(int $companyId, string $tipo): void
{
    $empresa = Company::withoutGlobalScopes()->findOrFail($companyId);
    $empresa->forceFill(['settings' => ['pos' => ['profile' => $tipo]]])->save();
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería Detalles'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@detalles.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->principal = Warehouse::query()->where('is_default', true)->firstOrFail();
});

// ------------------------------------------------------------------ Quién ve los detalles

it('una ferretería puede escribir marca, estante y referencia', function (): void {
    /*
     * El caso que motiva esto. Antes solo los veía el perfil «repuestos», así que una ferretería no
     * tenía dónde apuntar en qué estante está un tubo —y el mostrador ya enseña ese dato en la ficha,
     * con lo que salía vacío siempre—.
     */
    conPerfil($this->company->id, 'general');

    $html = $this->actingAs($this->duena)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->toContain('name="brand"')
        ->toContain('name="location"')
        ->toContain('name="part_number"')
        ->toContain('name="description"')
        // Pero NO los del vehículo: en una ferretería nadie va a rellenar «Marca del vehículo».
        ->not->toContain('name="vehicle_make"');
});

it('un negocio de comida rápida NO ve ninguno de esos campos', function (): void {
    /*
     * Es lo único que pediste excluir. Una empanada no tiene marca, ni estante, ni referencia; pedir
     * esos datos en cada alta es un formulario el doble de largo para dejarlo todo en blanco.
     */
    conPerfil($this->company->id, 'comida');

    $html = $this->actingAs($this->duena)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->not->toContain('name="brand"')
        ->not->toContain('name="location"')
        ->not->toContain('name="part_number"')
        ->not->toContain('name="vehicle_make"');
});

it('solo el negocio de repuestos ve los datos del vehículo', function (): void {
    conPerfil($this->company->id, 'repuestos');

    $html = $this->actingAs($this->duena)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->toContain('name="vehicle_make"')
        ->toContain('name="vehicle_model"')
        ->toContain('name="year_from"');
});

it('el tipo «Comida rápida» existe y se puede elegir', function (): void {
    // Sin él no habría forma de decir que un negocio es de comida, y la exclusión no se podría aplicar.
    expect(PosProfile::types())->toHaveKey('comida')
        ->and(PosProfile::pideDetalles('comida'))->toBeFalse()
        ->and(PosProfile::pideDetalles('general'))->toBeTrue()
        ->and(PosProfile::pideVehiculo('repuestos'))->toBeTrue()
        ->and(PosProfile::pideVehiculo('general'))->toBeFalse();
});

// ------------------------------------------------------------------ Que los datos se guarden

it('los detalles escritos al crear se guardan de verdad', function (): void {
    /*
     * El controlador guarda solo lo VALIDADO, así que un campo sin regla se descarta en silencio: se
     * escribe en el formulario y no llega a la base. Le pasaba a la descripción.
     */
    conPerfil($this->company->id, 'general');

    $this->actingAs($this->duena)->post(route('panel.products.store'), [
        'name' => 'Tubo PVC 4"', 'price' => '1250', 'unit' => 'unidad',
        'brand' => 'Tubopal', 'location' => 'Patio / Rack 2',
        'part_number' => 'TP-SDR26-4', 'description' => 'Con campana. No incluye pegamento.',
    ])->assertRedirect();

    $p = Product::firstWhere('name', 'Tubo PVC 4"');

    expect($p)->not->toBeNull()
        ->and($p->brand)->toBe('Tubopal')
        ->and($p->location)->toBe('Patio / Rack 2')
        ->and($p->part_number)->toBe('TP-SDR26-4')
        ->and($p->description)->toBe('Con campana. No incluye pegamento.');
});

// ------------------------------------------------------------------ En qué almacén entra

it('el stock inicial entra en el almacén ELEGIDO, no en el de por omisión', function (): void {
    /*
     * EL SEGUNDO FALLO. Estaba escrito a fuego igual que en el cobro, y con la misma consecuencia:
     * el inventario deja de decir dónde está la mercancía.
     */
    $sucursal = Warehouse::create([
        'company_id' => $this->company->id, 'name' => 'Sucursal 2', 'is_active' => true, 'is_default' => false,
    ]);

    $this->actingAs($this->duena)->post(route('panel.products.store'), [
        'name' => 'Amortiguador', 'price' => '4200', 'unit' => 'unidad',
        'initial_stock' => '6', 'warehouse_id' => $sucursal->id,
    ])->assertRedirect();

    $p = Product::firstWhere('name', 'Amortiguador');

    $enSucursal = Stock::withoutGlobalScopes()->where('product_id', $p->id)->where('warehouse_id', $sucursal->id)->value('quantity');
    $enPrincipal = Stock::withoutGlobalScopes()->where('product_id', $p->id)->where('warehouse_id', $this->principal->id)->value('quantity');

    expect((string) $enSucursal)->toBe('6.000')
        // Y el principal ni se toca.
        ->and($enPrincipal)->toBeNull();
});

it('sin elegir almacén sigue entrando en el de por omisión', function (): void {
    // Con un solo almacén ni se pregunta, así que no puede quedarse sin sitio donde entrar.
    $this->actingAs($this->duena)->post(route('panel.products.store'), [
        'name' => 'Martillo', 'price' => '450', 'unit' => 'unidad', 'initial_stock' => '4',
    ])->assertRedirect();

    $p = Product::firstWhere('name', 'Martillo');

    expect((string) Stock::withoutGlobalScopes()->where('product_id', $p->id)->where('warehouse_id', $this->principal->id)->value('quantity'))
        ->toBe('4.000');
});

it('no se puede meter stock en el almacén de otra empresa', function (): void {
    /*
     * `exists` consulta la tabla directamente, sin pasar por el aislamiento por empresa. Sin acotarlo
     * a mano, un id ajeno pasaría la validación y una empresa metería existencia en la de al lado.
     */
    $ajena = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    $suyo = Warehouse::withoutGlobalScopes()->where('company_id', $ajena->id)->firstOrFail();

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->duena)->post(route('panel.products.store'), [
        'name' => 'Intruso', 'price' => '10', 'unit' => 'unidad',
        'initial_stock' => '5', 'warehouse_id' => $suyo->id,
    ])->assertSessionHasErrors('warehouse_id');
});
