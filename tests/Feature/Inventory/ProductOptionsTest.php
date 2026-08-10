<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\SelectionType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería Opciones'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@opciones.test', 'password' => 'secret-password',
    ]), 'owner');

    $register = CashRegister::create(['name' => 'Caja', 'code' => 'C1', 'is_active' => true]);
    app(CashService::class)->open($register, '1000', $this->owner->id);

    // Un helado de 100 con dos grupos: tamaño (recargo) y sabor (sin recargo).
    $this->helado = Product::create(['sku' => 'CONO', 'name' => 'Cono', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase(
        $this->helado,
        Warehouse::query()->where('is_default', true)->orderBy('id')->first(),
        StockMovementType::Initial,
        '50',
    );

    $this->tamano = OptionGroup::create([
        'name' => 'Tamaño', 'selection_type' => SelectionType::Single, 'is_required' => true,
    ]);
    $this->unaBola = Option::create([
        'option_group_id' => $this->tamano->id, 'name' => '1 bola', 'price_delta' => '0',
    ]);
    $this->dosBolas = Option::create([
        'option_group_id' => $this->tamano->id, 'name' => '2 bolas', 'price_delta' => '60',
    ]);

    $this->sabor = OptionGroup::create([
        'name' => 'Sabor', 'selection_type' => SelectionType::Multiple,
    ]);
    $this->chocolate = Option::create([
        'option_group_id' => $this->sabor->id, 'name' => 'Chocolate', 'price_delta' => '0',
    ]);

    $this->helado->syncOptionGroups([$this->tamano->id, $this->sabor->id]);
});

/** Cobra el helado con las opciones indicadas. */
function cobrarHelado(array $optionIds, string $paid = '500'): void
{
    test()->actingAs(test()->owner)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => test()->helado->id, 'qty' => 1, 'options' => $optionIds]]),
            'paid' => $paid,
        ])
        ->assertSessionHas('pos_ok');
}

it('el recargo del tamaño se suma al precio del producto', function (): void {
    cobrarHelado([$this->dosBolas->id, $this->chocolate->id]);

    $item = Sale::query()->latest('id')->first()->items()->first();

    // 100 del cono + 60 de la segunda bola. El sabor no cobra.
    expect($item->unit_price)->toBe('160.00')
        ->and($item->subtotal)->toBe('160.00');
});

it('sin opciones el precio es el del catálogo, exactamente como antes', function (): void {
    cobrarHelado([]);

    expect(Sale::query()->latest('id')->first()->items()->first()->unit_price)->toBe('100.00');
});

it('congela el nombre del grupo y de la opción en la línea vendida', function (): void {
    cobrarHelado([$this->dosBolas->id, $this->chocolate->id]);

    $opciones = Sale::query()->latest('id')->first()->items()->first()->options()->get();

    expect($opciones)->toHaveCount(2)
        ->and($opciones->pluck('group_name')->all())->toContain('Tamaño', 'Sabor')
        ->and($opciones->pluck('option_name')->all())->toContain('2 bolas', 'Chocolate');
});

it('renombrar una opción no cambia lo que dice un recibo ya emitido', function (): void {
    cobrarHelado([$this->dosBolas->id]);

    $this->dosBolas->update(['name' => 'Doble', 'price_delta' => '999']);

    $opcion = Sale::query()->latest('id')->first()->items()->first()->options()->first();

    expect($opcion->option_name)->toBe('2 bolas')
        ->and($opcion->price_delta)->toBe('60.00');
});

it('borrar la opción no vacía el histórico de ventas', function (): void {
    cobrarHelado([$this->dosBolas->id]);

    $this->dosBolas->delete();

    $opcion = Sale::query()->latest('id')->first()->items()->first()->options()->first();

    expect($opcion)->not->toBeNull()
        ->and($opcion->option_name)->toBe('2 bolas')
        ->and($opcion->option_id)->toBeNull();
});

it('rechaza una opción que no pertenece a los grupos del producto', function (): void {
    // Grupo ajeno: existe en la empresa pero NO está asignado al helado.
    $ajeno = OptionGroup::create(['name' => 'Descuento pirata', 'selection_type' => SelectionType::Single]);
    $trampa = Option::create([
        'option_group_id' => $ajeno->id, 'name' => 'Gratis total', 'price_delta' => '-99',
    ]);

    cobrarHelado([$trampa->id]);

    // Se ignora: se cobra el precio del catálogo, sin el descuento inventado.
    $item = Sale::query()->latest('id')->first()->items()->first();

    expect($item->unit_price)->toBe('100.00')
        ->and($item->options()->count())->toBe(0);
});

it('rechaza una opción de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($otra->id);
    $grupoAjeno = OptionGroup::create(['name' => 'Ajeno', 'selection_type' => SelectionType::Single]);
    $opcionAjena = Option::create([
        'option_group_id' => $grupoAjeno->id, 'name' => 'Ajena', 'price_delta' => '-50',
    ]);
    app(CurrentCompany::class)->set($this->company->id);

    cobrarHelado([$opcionAjena->id]);

    expect(Sale::query()->latest('id')->first()->items()->first()->unit_price)->toBe('100.00');
});

it('ignora una opción desactivada', function (): void {
    $this->dosBolas->update(['is_active' => false]);

    cobrarHelado([$this->dosBolas->id]);

    expect(Sale::query()->latest('id')->first()->items()->first()->unit_price)->toBe('100.00');
});

it('el ITBIS se calcula sobre el precio con el recargo ya incluido', function (): void {
    cobrarHelado([$this->dosBolas->id]);

    $sale = Sale::query()->latest('id')->first();

    // Precio final 160 con ITBIS del 18% incluido: base = 160 / 1.18 = 135.59, impuesto = 24.41.
    expect($sale->total)->toBe('160.00')
        ->and($sale->subtotal)->toBe('135.59')
        ->and($sale->tax)->toBe('24.41');
});

it('un recargo negativo nunca deja el precio por debajo de cero', function (): void {
    $rebaja = Option::create([
        'option_group_id' => $this->tamano->id, 'name' => 'Cortesía', 'price_delta' => '-500',
    ]);

    cobrarHelado([$rebaja->id], '0');

    expect(Sale::query()->latest('id')->first()->items()->first()->unit_price)->toBe('0.00');
});

it('el máximo y el mínimo de un grupo dependen de su tipo, no solo de la columna', function (): void {
    // En un grupo de selección única el tipo manda: aunque la columna diga otra cosa, el máximo es 1.
    $this->tamano->update(['max_selections' => 5]);

    expect($this->tamano->fresh()->maxAllowed())->toBe(1)
        ->and($this->tamano->fresh()->minRequired())->toBe(1);

    $this->sabor->update(['is_required' => true, 'min_selections' => 2, 'max_selections' => 3]);

    expect($this->sabor->fresh()->maxAllowed())->toBe(3)
        ->and($this->sabor->fresh()->minRequired())->toBe(2);
});
