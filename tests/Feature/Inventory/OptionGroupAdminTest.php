<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Pantalla de tamaños y sabores.
 *
 * Hasta ahora los grupos de opciones solo se podían crear por consola, así que en la práctica no se
 * usaban: la funcionalidad existía pero era inalcanzable. Esta pantalla la pone en manos del dueño.
 *
 * Lo que se cubre aquí no es el CRUD por el CRUD, sino lo que puede estropear una venta: grupos
 * imposibles de satisfacer, recargos mal guardados y quién tiene permiso para tocar precios.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Datos válidos de un grupo. */
function datosGrupo(array $extra = []): array
{
    return array_merge([
        'name' => 'Tamaño',
        'selection_type' => 'single',
    ], $extra);
}

it('crea un grupo de opciones desde la pantalla', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.option-groups.store'), datosGrupo())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $grupo = OptionGroup::firstWhere('name', 'Tamaño');

    expect($grupo)->not->toBeNull()
        ->and($grupo->company_id)->toBe($this->company->id)
        ->and($grupo->isMultiple())->toBeFalse();
});

it('añade opciones con su recargo', function (): void {
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);

    $this->actingAs($this->owner)
        ->post(route('panel.options.store', $grupo), ['name' => '2 bolas', 'price_delta' => '60'])
        ->assertSessionHasNoErrors();

    $opcion = Option::firstWhere('name', '2 bolas');

    expect((float) $opcion->price_delta)->toBe(60.0)
        ->and($opcion->option_group_id)->toBe($grupo->id);
});

it('acepta un recargo negativo, que descuenta del precio', function (): void {
    // Un «tamaño pequeño» más barato que el base es un caso real, no un error de tecleo.
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);

    $this->actingAs($this->owner)
        ->post(route('panel.options.store', $grupo), ['name' => 'Mini', 'price_delta' => '-25'])
        ->assertSessionHasNoErrors();

    expect((float) Option::firstWhere('name', 'Mini')->price_delta)->toBe(-25.0);
});

it('rechaza un grupo que nadie podría completar', function (): void {
    // Mínimo 3 con máximo 2 dejaría el producto imposible de añadir al ticket, y el cajero no
    // tendría forma de saber por qué.
    $this->actingAs($this->owner)
        ->post(route('panel.option-groups.store'), datosGrupo([
            'name' => 'Sabores', 'selection_type' => 'multiple',
            'min_selections' => 3, 'max_selections' => 2,
        ]))
        ->assertSessionHasErrors('max_selections');

    expect(OptionGroup::count())->toBe(0);
});

it('descarta los límites en un grupo de elegir una', function (): void {
    // Guardar «mínimo 2» donde solo se puede elegir una sería una regla imposible arrastrada en
    // silencio. Se limpia al guardar, no se confía en que el formulario no la mande.
    $this->actingAs($this->owner)
        ->post(route('panel.option-groups.store'), datosGrupo([
            'selection_type' => 'single', 'min_selections' => 2, 'max_selections' => 5,
        ]))
        ->assertSessionHasNoErrors();

    $grupo = OptionGroup::firstWhere('name', 'Tamaño');

    expect($grupo->min_selections)->toBe(0)
        ->and($grupo->max_selections)->toBeNull()
        ->and($grupo->minRequired())->toBe(0);
});

it('asigna el grupo a los productos elegidos y lo retira de los demás', function (): void {
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);
    $helado = Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1']);
    $agua = Product::create(['name' => 'Agua', 'price' => '50', 'sku' => 'A-1']);

    $this->actingAs($this->owner)
        ->put(route('panel.option-groups.products', $grupo), ['products' => [$helado->id, $agua->id]])
        ->assertSessionHasNoErrors();

    expect($grupo->products()->count())->toBe(2);

    // Se vuelve a guardar dejando solo el helado: el agua debe soltarse.
    $this->actingAs($this->owner)
        ->put(route('panel.option-groups.products', $grupo), ['products' => [$helado->id]]);

    expect($grupo->fresh()->products()->pluck('products.id')->all())->toBe([$helado->id]);
});

it('permite dejar el grupo sin ningún producto', function (): void {
    // El formulario manda un valor de relleno vacío para que «desmarcar todo» llegue al servidor.
    // Si no se descartara, la validación de entero fallaría y no se podría vaciar la lista.
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);
    $helado = Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1']);
    $grupo->products()->attach($helado->id, ['company_id' => $this->company->id, 'sort_order' => 0]);

    $this->actingAs($this->owner)
        ->put(route('panel.option-groups.products', $grupo), ['products' => ['']])
        ->assertSessionHasNoErrors();

    expect($grupo->fresh()->products()->count())->toBe(0);
});

it('al borrar el grupo lo desengancha de los productos', function (): void {
    // Si quedara enganchado, un producto seguiría pidiendo un grupo que ya no se administra desde
    // ninguna pantalla y nadie entendería de dónde sale.
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);
    $helado = Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1']);
    $grupo->products()->attach($helado->id, ['company_id' => $this->company->id, 'sort_order' => 0]);

    $this->actingAs($this->owner)
        ->delete(route('panel.option-groups.destroy', $grupo))
        ->assertSessionHasNoErrors();

    expect($helado->fresh()->optionGroups()->count())->toBe(0);
});

it('un cajero no puede tocar los grupos: ahí se fijan precios', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.option-groups'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.option-groups.store'), datosGrupo())->assertForbidden();

    expect(OptionGroup::count())->toBe(0);
});

it('no deja tocar un grupo de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = OptionGroup::create(datosGrupo(['name' => 'Ajeno']) + ['is_active' => true]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->delete(route('panel.option-groups.destroy', $ajeno))
        ->assertNotFound();
});

it('la pantalla muestra los grupos con sus opciones', function (): void {
    $grupo = OptionGroup::create(datosGrupo() + ['is_active' => true]);
    $grupo->options()->create([
        'company_id' => $this->company->id, 'name' => '2 bolas',
        'price_delta' => '60', 'sort_order' => 1, 'is_active' => true,
    ]);

    $this->actingAs($this->owner)->get(route('panel.option-groups'))
        ->assertOk()
        ->assertSee('Tamaño')
        ->assertSee('2 bolas');
});
