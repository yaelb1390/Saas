<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Services\KeywordMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El algoritmo de coincidencia local del sandbox. Los casos son exactamente los que pide la
 * sección 39 del prompt maestro: mayúsculas, acentos, y que «precioso» no dispare con «precio».
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tienda'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->product = Product::create(['sku' => 'CAM-1', 'name' => 'Camisa', 'cost' => '1', 'price' => '2']);
});

function reglaConPalabras(int $productId, array $keywords, string $matchMode = 'word', bool $typoTolerance = false): Rule
{
    return Rule::factory()->create([
        'product_id' => $productId, 'keywords' => $keywords, 'match_mode' => $matchMode, 'typo_tolerance' => $typoTolerance,
    ]);
}

it('coincide sin importar mayúsculas', function (): void {
    $rule = reglaConPalabras($this->product->id, ['precio']);

    expect(app(KeywordMatcher::class)->matches($rule, 'PRECIO'))->toBe('precio');
});

it('coincide sin importar los acentos', function (): void {
    $rule = reglaConPalabras($this->product->id, ['cuanto']);

    expect(app(KeywordMatcher::class)->matches($rule, '¿Cuánto?'))->toBe('cuanto');
});

it('en modo palabra, "precioso" NO coincide con "precio"', function (): void {
    $rule = reglaConPalabras($this->product->id, ['precio']);

    expect(app(KeywordMatcher::class)->matches($rule, 'qué precioso vestido'))->toBeNull();
});

it('en modo "en cualquier parte", "precioso" SÍ coincide con "precio"', function (): void {
    $rule = reglaConPalabras($this->product->id, ['precio'], matchMode: 'contains');

    expect(app(KeywordMatcher::class)->matches($rule, 'qué precioso vestido'))->toBe('precio');
});

it('en modo exacto, solo coincide si el comentario es la palabra y nada más', function (): void {
    $rule = reglaConPalabras($this->product->id, ['precio'], matchMode: 'exact');

    expect(app(KeywordMatcher::class)->matches($rule, 'precio'))->toBe('precio')
        ->and(app(KeywordMatcher::class)->matches($rule, 'el precio'))->toBeNull();
});

it('con tolerancia a erratas, acepta una palabra corta escrita casi igual', function (): void {
    $rule = reglaConPalabras($this->product->id, ['cuanto'], typoTolerance: true);

    expect(app(KeywordMatcher::class)->matches($rule, 'cuato'))->toBe('cuanto'); // falta una letra
});

it('sin tolerancia a erratas, la misma palabra mal escrita NO coincide', function (): void {
    $rule = reglaConPalabras($this->product->id, ['cuanto'], typoTolerance: false);

    expect(app(KeywordMatcher::class)->matches($rule, 'cuato'))->toBeNull();
});

it('no coincide si ninguna palabra clave aparece', function (): void {
    $rule = reglaConPalabras($this->product->id, ['precio', 'cuanto']);

    expect(app(KeywordMatcher::class)->matches($rule, 'me encanta este color'))->toBeNull();
});
