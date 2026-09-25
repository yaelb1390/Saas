<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Services\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa Nike Air', 'cost' => '800', 'price' => '1500']);
});

it('rellena las variables conocidas', function (): void {
    $texto = app(TemplateRenderer::class)->render('La {producto} cuesta {precio}.', $this->product, $this->company);

    expect($texto)->toBe('La Camisa Nike Air cuesta DOP 1,500.00.');
});

it('deja tal cual una variable que no reconoce, sin adivinar nada', function (): void {
    $texto = app(TemplateRenderer::class)->render('Hola {inventado}', $this->product, $this->company);

    expect($texto)->toBe('Hola {inventado}');
});

it('detecta las variables desconocidas para que el formulario las rechace', function (): void {
    $desconocidas = app(TemplateRenderer::class)->variablesDesconocidas('La {producto} cuesta {precio}, {tallas} disponibles');

    expect($desconocidas)->toBe(['tallas']);
});

it('no reporta nada cuando todas las variables son válidas', function (): void {
    expect(app(TemplateRenderer::class)->variablesDesconocidas('{producto} a {precio} ({moneda})'))->toBe([]);
});
