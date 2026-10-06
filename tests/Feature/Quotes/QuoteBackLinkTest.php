<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Quotes\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El botón «Volver» lo pinta el layout del panel cuando la pantalla lo pide (`back` + `back-label`).
 * Se prueba con Cotizaciones: la ficha y el formulario de edición antes no tenían forma de volver,
 * y el listado, que es pantalla principal, no debe llevarlo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@volver.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $producto = Product::create(['sku' => 'CEM', 'name' => 'Cemento', 'price' => '450']);
    $this->quote = app(QuoteService::class)->crear(
        [['product_id' => $producto->id, 'quantity' => '1', 'unit_price' => '450']],
        ['customer_name' => 'Juan'],
    );
});

it('la ficha de una cotización lleva el botón Volver al listado', function (): void {
    $this->actingAs($this->owner)->get(route('panel.quotes.show', $this->quote))
        ->assertOk()
        ->assertSee('class="bmos-back"', false)
        ->assertSee('href="'.route('panel.quotes.index').'"', false)
        ->assertSee('Cotizaciones');
});

it('al editar, Volver lleva a la ficha de esa cotización', function (): void {
    $this->actingAs($this->owner)->get(route('panel.quotes.edit', $this->quote))
        ->assertOk()
        ->assertSee('href="'.route('panel.quotes.show', $this->quote).'" class="bmos-back"', false)
        ->assertSee('Cotización '.$this->quote->code);
});

it('una pantalla principal no lleva botón Volver', function (): void {
    $this->actingAs($this->owner)->get(route('panel.quotes.index'))
        ->assertOk()
        ->assertDontSee('class="bmos-back"', false);
});
