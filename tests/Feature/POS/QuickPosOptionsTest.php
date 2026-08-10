<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\SelectionType;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Opciones POS'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@opcpos.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->helado = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);

    $this->tamano = OptionGroup::create([
        'name' => 'Tamaño', 'selection_type' => SelectionType::Single, 'is_required' => true,
    ]);
    Option::create(['option_group_id' => $this->tamano->id, 'name' => '1 bola', 'price_delta' => '0']);
    Option::create(['option_group_id' => $this->tamano->id, 'name' => '2 bolas', 'price_delta' => '60']);
});

it('la rejilla avisa de qué productos piden elegir opciones', function (): void {
    Product::create(['sku' => 'AGUA', 'name' => 'Agua', 'cost' => '1', 'price' => '45']);
    $this->helado->syncOptionGroups([$this->tamano->id]);

    $results = collect($this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.catalog'))->assertOk()->json('results'))
        ->keyBy('sku');

    expect($results['CONO']['has_options'])->toBeTrue()
        ->and($results['AGUA']['has_options'])->toBeFalse();
});

it('devuelve los grupos y opciones de un producto', function (): void {
    $this->helado->syncOptionGroups([$this->tamano->id]);

    $grupos = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.options', $this->helado))
        ->assertOk()
        ->json('groups');

    expect($grupos)->toHaveCount(1)
        ->and($grupos[0]['name'])->toBe('Tamaño')
        ->and($grupos[0]['multiple'])->toBeFalse()
        // Obligatorio y de una sola elección: exactamente una.
        ->and($grupos[0]['min'])->toBe(1)
        ->and($grupos[0]['max'])->toBe(1)
        ->and($grupos[0]['options'])->toHaveCount(2)
        ->and($grupos[0]['options'][1]['price_delta'])->toBe('60.00');
});

it('no ofrece grupos ni opciones desactivados', function (): void {
    $inactivo = OptionGroup::create([
        'name' => 'Retirado', 'selection_type' => SelectionType::Multiple, 'is_active' => false,
    ]);
    Option::create(['option_group_id' => $this->tamano->id, 'name' => 'Descatalogada', 'is_active' => false]);

    $this->helado->syncOptionGroups([$this->tamano->id, $inactivo->id]);

    $grupos = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.options', $this->helado))->assertOk()->json('groups');

    expect($grupos)->toHaveCount(1)
        ->and($grupos[0]['options'])->toHaveCount(2);
});

it('un producto sin opciones devuelve la lista vacía', function (): void {
    $grupos = $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.options', $this->helado))->assertOk()->json('groups');

    expect($grupos)->toBe([]);
});

it('no expone las opciones de un producto de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['sku' => 'AJENO', 'name' => 'Ajeno', 'cost' => '1', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.options', $ajeno))
        ->assertNotFound();
});

it('pedir las opciones no dispara una consulta por opción', function (): void {
    $sabor = OptionGroup::create(['name' => 'Sabor', 'selection_type' => SelectionType::Multiple]);
    foreach (range(1, 10) as $i) {
        Option::create(['option_group_id' => $sabor->id, 'name' => "Sabor {$i}", 'price_delta' => '0']);
    }
    $this->helado->syncOptionGroups([$this->tamano->id, $sabor->id]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($this->owner)
        ->getJson(route('panel.quick-pos.options', $this->helado))->assertOk();

    // Con 12 opciones repartidas en 2 grupos, un N+1 se dispararía muy por encima de esto.
    expect($queries)->toBeLessThan(12, "Se ejecutaron {$queries} consultas.");
});
