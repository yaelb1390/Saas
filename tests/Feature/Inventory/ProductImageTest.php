<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Fotos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@fotos.test', 'password' => 'secret-password',
    ]), 'owner');

    Storage::fake('local');
});

it('crea un producto con foto, la guarda en disco y la sirve', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), [
            'sku' => 'P1', 'name' => 'Camiseta', 'price' => '500',
            'image' => UploadedFile::fake()->image('foto.jpg', 1200, 1200),
        ])
        ->assertRedirect();

    $product = Product::firstWhere('sku', 'P1');

    expect($product->image_path)->not->toBeNull()
        ->and($product->hasImage())->toBeTrue();
    Storage::disk('local')->assertExists($product->image_path);

    $response = $this->actingAs($this->owner)->get(route('panel.products.image', $product))->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image');
});

it('el payload del POS incluye la URL de la foto (o null si no tiene)', function (): void {
    $this->actingAs($this->owner)->post(route('panel.products.store'), [
        'sku' => 'P2', 'name' => 'Gorra Roja', 'price' => '300',
        'image' => UploadedFile::fake()->image('g.jpg', 800, 800),
    ]);
    $this->actingAs($this->owner)->post(route('panel.products.store'), [
        'sku' => 'P3', 'name' => 'Gorra Azul', 'price' => '300',
    ]);

    $rows = collect(app(ProductLookupPresenter::class)->search('Gorra', 10));

    expect($rows->firstWhere('sku', 'P2')['image'])->toContain('/imagen')
        ->and($rows->firstWhere('sku', 'P3')['image'])->toBeNull();
});

it('reemplaza la foto anterior al subir una nueva', function (): void {
    $this->actingAs($this->owner)->post(route('panel.products.store'), [
        'sku' => 'P5', 'name' => 'Bulto', 'price' => '700',
        'image' => UploadedFile::fake()->image('a.jpg'),
    ]);
    $product = Product::firstWhere('sku', 'P5');
    $old = $product->image_path;

    $this->actingAs($this->owner)->put(route('panel.products.update', $product), [
        'sku' => 'P5', 'name' => 'Bulto', 'price' => '700',
        'image' => UploadedFile::fake()->image('b.jpg'),
    ])->assertRedirect();

    $product->refresh();
    expect($product->image_path)->not->toBe($old);
    Storage::disk('local')->assertMissing($old);          // la vieja se borró
    Storage::disk('local')->assertExists($product->image_path);
});

it('no sirve la foto de un producto de otra empresa', function (): void {
    $this->actingAs($this->owner)->post(route('panel.products.store'), [
        'sku' => 'P4', 'name' => 'Zapato', 'price' => '900',
        'image' => UploadedFile::fake()->image('z.jpg'),
    ]);
    $product = Product::firstWhere('sku', 'P4');

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    $intruso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Intruso',
        'email' => 'intruso@otra.test', 'password' => 'secret-password',
    ]), 'owner');
    app(CurrentCompany::class)->set($otra->id);

    $this->actingAs($intruso)->get(route('panel.products.image', $product))->assertNotFound();
});

// ------------------------------------------------------------------ Encender y apagar las fotos

/*
 * LAS FOTOS SON UNA FUNCIÓN QUE SE APAGA, NO UNA OBLIGACIÓN.
 *
 * Un colmado con seiscientos artículos no va a fotografiarlos uno a uno: el campo de subir en cada
 * alta y el hueco gris en cada fila del inventario son estorbo puro. Una cafetería o una tienda de
 * ropa venden con los ojos y las quieren. Por eso lo decide el dueño en «Mi empresa».
 *
 * Viene APAGADA de fábrica a propósito: es lo contrario de lo habitual, pero encaja con el negocio
 * típico de aquí, donde la mayoría no fotografía nada.
 */
it('de fabrica el inventario no pide fotos', function (): void {
    $this->withoutVite();

    $html = $this->actingAs($this->owner)->get(route('panel.products'))->assertOk()->getContent();

    /*
     * Se mira el USO, no la definición: la función de JavaScript se sirve siempre —vive en el
     * bloque de scripts de la pantalla— y comprobarla daría un test que falla por el motivo
     * equivocado. Lo que tiene que desaparecer es el campo y su rótulo.
     */
    expect($html)->not->toContain('x-data="avisoFotoVertical()"')
        ->and($html)->not->toContain('Foto del producto');
});

it('encendida, el inventario vuelve a ofrecerlas', function (): void {
    $this->withoutVite();

    $this->company->update(['settings' => ['features' => ['product_images' => true]]]);

    $html = $this->actingAs($this->owner)->get(route('panel.products'))->assertOk()->getContent();

    expect($html)->toContain('x-data="avisoFotoVertical()"')
        ->and($html)->toContain('Foto del producto');
});

/*
 * Y APAGARLA NO PIERDE NADA. Es la mitad que de verdad importa: la pantalla de «Mi empresa» promete
 * que «no se borra nada», y sin este test esa promesa no la sujeta nadie.
 */
it('apagarla no borra las fotos que ya estaban', function (): void {
    $producto = Product::create([
        'sku' => 'CON-FOTO', 'name' => 'Con foto', 'cost' => '10', 'price' => '20',
        'image_path' => 'productos/una-foto.jpg',
    ]);

    $this->company->update(['settings' => ['features' => ['product_images' => false]]]);

    expect($producto->fresh()->image_path)->toBe('productos/una-foto.jpg')
        ->and($producto->fresh()->hasImage())->toBeTrue();
});
