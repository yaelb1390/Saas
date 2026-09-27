<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Services\QuoteConverter;
use App\Modules\Quotes\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Editar y eliminar cotizaciones desde el panel.
 *
 * Las dos comparten una misma regla de fondo: una cotización CONVERTIDA ya es una venta de verdad, y
 * ni se edita ni se elimina —lo demás (borrador, enviada, aceptada, rechazada, caducada) sí—. El
 * borrado es lógico (`SoftDeletes`), igual que el resto del panel.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería El Progreso'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@cotizar-mgmt.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'CEM', 'name' => 'Cemento', 'cost' => '300', 'price' => '450']);
    $this->otro = Product::create(['sku' => 'ARE', 'name' => 'Arena', 'cost' => '100', 'price' => '150']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '20');
    app(StockService::class)->increase($this->otro, $this->warehouse, StockMovementType::Purchase, '20');

    app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');

    $this->quotes = app(QuoteService::class);

    $this->quote = $this->quotes->crear(
        [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '450']],
        ['customer_name' => 'Juan', 'customer_phone' => '18095551234'],
    );
});

// ------------------------------------------------------------------------------------- Editar

it('el dueño puede abrir el formulario de edición de una cotización en borrador', function (): void {
    $this->actingAs($this->owner)->get(route('panel.quotes.edit', $this->quote))
        ->assertOk()
        ->assertSee($this->quote->code);
});

it('editar cambia el cliente, la fecha, las notas y las líneas, y vuelve a sumar el total', function (): void {
    $respuesta = $this->actingAs($this->owner)->put(route('panel.quotes.update', $this->quote), [
        'customer_name' => 'Pedro',
        'customer_phone' => '18095559999',
        'valid_until' => now()->addDays(10)->format('Y-m-d'),
        'notes' => 'Entrega en el local',
        'lines' => [
            ['product_id' => $this->otro->id, 'quantity' => '3', 'unit_price' => '150'],
        ],
    ]);

    $respuesta->assertRedirect(route('panel.quotes.show', $this->quote));

    $fresco = $this->quote->fresh('items');

    expect($fresco->customer_name)->toBe('Pedro')
        ->and($fresco->customer_phone)->toBe('18095559999')
        ->and($fresco->notes)->toBe('Entrega en el local')
        ->and($fresco->items)->toHaveCount(1)
        ->and($fresco->items->first()->product_id)->toBe($this->otro->id)
        // 3 × 150 = 450, con el mismo desglose de ITBIS que ya usa una venta.
        ->and((float) $fresco->total)->toBeGreaterThan(0.0);
});

it('una cotización CONVERTIDA no se puede editar: ni el formulario ni el guardado', function (): void {
    app(QuoteConverter::class)->convertir($this->quote);
    $convertida = $this->quote->fresh();

    $this->actingAs($this->owner)->get(route('panel.quotes.edit', $convertida))
        ->assertRedirect(route('panel.quotes.show', $convertida))
        ->assertSessionHas('panel_error');

    $respuesta = $this->actingAs($this->owner)->put(route('panel.quotes.update', $convertida), [
        'customer_name' => 'Otro nombre',
        'lines' => [['description' => 'Algo', 'quantity' => '1', 'unit_price' => '10']],
    ]);

    $respuesta->assertRedirect()->assertSessionHas('panel_error');
    expect($convertida->fresh()->customer_name)->toBe('Juan'); // no cambió
});

// ------------------------------------------------------------------------------------ Eliminar

it('eliminar una cotización la archiva: deja de listarse pero sigue en la base', function (): void {
    $this->actingAs($this->owner)->delete(route('panel.quotes.destroy', $this->quote))
        ->assertRedirect(route('panel.quotes.index'))
        ->assertSessionHas('panel_ok');

    expect(Quote::query()->find($this->quote->id))->toBeNull()
        ->and(Quote::withTrashed()->find($this->quote->id))->not->toBeNull();
});

it('una cotización CONVERTIDA no se puede eliminar', function (): void {
    app(QuoteConverter::class)->convertir($this->quote);
    $convertida = $this->quote->fresh();

    $this->actingAs($this->owner)->delete(route('panel.quotes.destroy', $convertida))
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Quote::query()->find($convertida->id))->not->toBeNull();
});

it('eliminar varias salta las convertidas y borra el resto, y lo dice en el mensaje', function (): void {
    $borrador = $this->quotes->crear(
        [['product_id' => $this->otro->id, 'quantity' => '1', 'unit_price' => '150']],
        ['customer_name' => 'Ana'],
    );

    app(QuoteConverter::class)->convertir($this->quote);
    $convertida = $this->quote->fresh();

    $respuesta = $this->actingAs($this->owner)->delete(route('panel.quotes.destroyMultiple'), [
        'ids' => [$convertida->id, $borrador->id],
    ]);

    $respuesta->assertRedirect(route('panel.quotes.index'));
    $mensaje = session('panel_ok');

    expect($mensaje)->toContain('1 cotización eliminada')
        ->and($mensaje)->toContain('1 ya estaba convertida')
        ->and(Quote::query()->find($borrador->id))->toBeNull()
        ->and(Quote::query()->find($convertida->id))->not->toBeNull();
});

// --------------------------------------------------------------------------------- Permisos

it('un cajero no puede editar ni eliminar cotizaciones', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajera',
        'email' => 'cajera@cotizar-mgmt.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.quotes.edit', $this->quote))->assertForbidden();
    $this->actingAs($cajero)->put(route('panel.quotes.update', $this->quote), [])->assertForbidden();
    $this->actingAs($cajero)->delete(route('panel.quotes.destroy', $this->quote))->assertForbidden();
    $this->actingAs($cajero)->delete(route('panel.quotes.destroyMultiple'), ['ids' => [$this->quote->id]])->assertForbidden();

    expect(Quote::query()->find($this->quote->id))->not->toBeNull();
});

// ------------------------------------------------------------------------------------- Lista

it('la lista muestra los botones de editar y eliminar, y no los enseña para una convertida', function (): void {
    app(QuoteConverter::class)->convertir($this->quote);

    $editable = $this->quotes->crear(
        [['product_id' => $this->otro->id, 'quantity' => '1', 'unit_price' => '150']],
        ['customer_name' => 'Ana'],
    );

    $respuesta = $this->actingAs($this->owner)->get(route('panel.quotes.index'));

    $respuesta->assertOk()
        ->assertSee(route('panel.quotes.edit', $editable), false)
        ->assertDontSee(route('panel.quotes.edit', $this->quote->fresh()), false);
});
