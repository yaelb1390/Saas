<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Fotos Verticales'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@verticales.test', 'password' => 'secret-password',
    ]), 'owner');

    Storage::fake('local');
});

/** Sube una foto de las dimensiones dadas y devuelve el producto creado. */
function subirFoto(int $ancho, int $alto, string $nombre = 'Producto'): Product
{
    test()->actingAs(test()->owner)->post(route('panel.products.store'), [
        'name' => $nombre,
        'price' => '100',
        'image' => UploadedFile::fake()->image('foto.jpg', $ancho, $alto),
    ])->assertSessionHasNoErrors();

    return Product::firstWhere('name', $nombre);
}

/** @return array{0: int, 1: int} ancho y alto de la foto guardada */
function medidasGuardadas(Product $product): array
{
    $bytes = Storage::disk('local')->get((string) $product->image_path);
    $img = imagecreatefromstring($bytes);

    return [imagesx($img), imagesy($img)];
}

/** ¿La foto quedó en la proporción vertical 3:4 de la ficha? */
function esVertical3x4(Product $product): bool
{
    [$ancho, $alto] = medidasGuardadas($product);

    return $ancho === (int) round($alto * 3 / 4);
}

it('recuadra a vertical 3:4 una foto vertical', function (): void {
    // Una botella: alta y estrecha. Es el caso que mejor encaja y apenas gana margen.
    expect(esVertical3x4(subirFoto(400, 900, 'Botella')))->toBeTrue();
});

it('recuadra a vertical 3:4 una foto apaisada', function (): void {
    expect(esVertical3x4(subirFoto(1200, 500, 'Pizza')))->toBeTrue();
});

it('recuadra a vertical 3:4 una foto cuadrada', function (): void {
    // Una cuadrada de 600 pasa a 600×800: gana franjas arriba y abajo, que es justo lo que avisa
    // el formulario al elegir el archivo.
    expect(medidasGuardadas(subirFoto(600, 600, 'Cuadrada')))->toBe([600, 800]);
});

it('no amplía una foto pequeña: el lienzo se ajusta a ella', function (): void {
    // Estirar una foto de 120px hasta 800 la dejaría pixelada; es peor que mostrarla pequeña.
    expect(medidasGuardadas(subirFoto(120, 90, 'Diminuta')))->toBe([120, 160]);
});

it('no pasa del tope de 800px de alto aunque la foto sea enorme', function (): void {
    expect(medidasGuardadas(subirFoto(2400, 1600, 'Enorme')))->toBe([600, 800]);
});

it('el comando recuadra las fotos que ya estaban guardadas', function (): void {
    $product = subirFoto(400, 900, 'Antigua');

    // Se simula el estado anterior al cambio: una foto guardada sin recuadrar.
    Storage::disk('local')->put(
        (string) $product->image_path,
        (string) UploadedFile::fake()->image('vieja.jpg', 300, 700)->get(),
    );

    expect(medidasGuardadas($product->fresh()))->toBe([300, 700]);

    $this->artisan('productos:normalizar-fotos')->assertSuccessful();

    expect(esVertical3x4($product->fresh()))->toBeTrue();
});

it('el comando no vuelve a comprimir una foto que ya está en 3:4', function (): void {
    $product = subirFoto(400, 900, 'Ya recuadrada');
    $antes = medidasGuardadas($product);

    $this->artisan('productos:normalizar-fotos')
        ->expectsOutputToContain('Fotos recuadradas: 0')
        ->assertSuccessful();

    expect(medidasGuardadas($product->fresh()))->toBe($antes);
});

it('el modo de prueba del comando no toca ningún archivo', function (): void {
    $product = subirFoto(400, 900, 'Intacta');

    Storage::disk('local')->put(
        (string) $product->image_path,
        (string) UploadedFile::fake()->image('vieja.jpg', 300, 700)->get(),
    );

    $this->artisan('productos:normalizar-fotos', ['--dry-run' => true])->assertSuccessful();

    expect(medidasGuardadas($product->fresh()))->toBe([300, 700]);
});
