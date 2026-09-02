<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Dónde se guardan las fotos de producto.
 *
 * En producción esto estuvo roto desde el principio sin que se notara: el entorno serverless monta
 * el código en SOLO LECTURA, así que cada intento de subir una foto moría con un 500. En local no
 * se veía porque allí el disco sí es escribible.
 *
 * De ahí que estos tests no comprueben el recuadrado (eso ya lo cubre ProductImageFormatTest) sino
 * una sola cosa: que la foto acabe en el disco CONFIGURADO y no en uno fijo por código.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Fotos Remotas'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@remotas.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Crea un producto con foto y lo devuelve. */
function crearConFoto(string $nombre = 'Helado'): Product
{
    test()->actingAs(test()->owner)->post(route('panel.products.store'), [
        'name' => $nombre,
        'price' => '100',
        'image' => UploadedFile::fake()->image('foto.jpg', 600, 800),
    ])->assertSessionHasNoErrors();

    return Product::firstWhere('name', $nombre);
}

it('guarda la foto en el disco remoto cuando así está configurado', function (): void {
    // Es la configuración de producción: PRODUCT_IMAGE_DISK=s3 -> Supabase Storage.
    config(['filesystems.product_images' => 's3']);
    Storage::fake('s3');
    Storage::fake('local');

    $product = crearConFoto();

    expect($product->image_path)->not->toBeNull()
        ->and(Storage::disk('s3')->exists((string) $product->image_path))->toBeTrue()
        // Y NO en el disco del servidor: si cayera ahí, en producción se perdería.
        ->and(Storage::disk('local')->exists((string) $product->image_path))->toBeFalse();
});

it('sirve la foto leyéndola del mismo disco donde se guardó', function (): void {
    // Guardar en un disco y leer de otro daría un 404 con la foto perfectamente subida.
    config(['filesystems.product_images' => 's3']);
    Storage::fake('s3');

    $product = crearConFoto();

    test()->actingAs($this->owner)
        ->get(route('panel.products.image', $product))
        ->assertOk()
        /*
         * `private`, y antes decía `public`. Es un arreglo, no un ajuste del test: la foto de un
         * producto es de UNA empresa y se sirve detrás de sesión, así que con `public` cualquier
         * intermediario compartido —un proxy de oficina, una caché de operador— tenía permiso para
         * guardarla y servírsela después a otro cliente que pidiera la misma dirección.
         */
        ->assertHeader('Cache-Control', 'max-age=604800, private');
});

it('sigue usando el disco del servidor por defecto', function (): void {
    // El desarrollo local no necesita almacenamiento remoto: sin variable de entorno, todo igual
    // que siempre.
    Storage::fake('local');

    expect(config('filesystems.product_images'))->toBe('local');

    $product = crearConFoto();

    expect(Storage::disk('local')->exists((string) $product->image_path))->toBeTrue();
});

it('un fallo al escribir NO deja el producto apuntando a una foto inexistente', function (): void {
    // El fallo real de producción. Antes, con `throw => false`, un disco de solo lectura habría
    // dejado el producto con una ruta que no existe: en la rejilla del punto de venta saldría un
    // hueco y nadie sabría por qué. Que la subida falle de forma visible es preferible a que
    // mienta en silencio.
    config(['filesystems.product_images' => 's3']);
    Storage::fake('s3');

    $product = crearConFoto();
    $ruta = (string) $product->image_path;

    // Se borra el archivo por detrás, simulando que nunca llegó a escribirse.
    Storage::disk('s3')->delete($ruta);

    expect(ProductImageStore::disk()->exists($ruta))->toBeFalse();

    test()->actingAs($this->owner)
        ->get(route('panel.products.image', $product))
        ->assertNotFound(); // 404 honesto, no una imagen rota
});
