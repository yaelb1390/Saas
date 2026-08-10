<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Módulos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@modulos.test', 'password' => 'secret-password',
    ]), 'owner');
});

/** Fija los módulos contratados de la empresa y refresca el tenant activo. */
function conModulos(array $modules): void
{
    test()->company->update(['modules' => $modules]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set(test()->company->id);
}

it('«Venta rápida» se ofrece como módulo vendible', function (): void {
    expect(ModuleRegistry::exists('quick_pos'))->toBeTrue()
        ->and(ModuleRegistry::label('quick_pos'))->toBe('Venta rápida');
});

it('una empresa con solo la venta rápida entra al terminal', function (): void {
    conModulos(['quick_pos']);

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))->assertOk();
});

it('una empresa con solo la venta rápida PUEDE abrir caja y cobrar', function (): void {
    // El cobro es un endpoint compartido con el POS de mostrador. Si exigiera «pos», quien contrate
    // solo la venta rápida tendría una pantalla que no puede cobrar: un módulo inservible.
    conModulos(['quick_pos']);

    $this->actingAs($this->owner)
        ->post(route('panel.pos.open'), ['opening_amount' => '1000'])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->post(route('panel.pos.checkout'), ['cart' => '[]', 'paid' => '0'])
        // Llega al controlador (ticket vacío) en vez de rebotar con 403 por módulo.
        ->assertSessionHas('pos_error', 'El ticket está vacío.');
});

it('una empresa con solo el POS de mostrador no entra al terminal táctil', function (): void {
    conModulos(['pos']);

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))->assertForbidden();
});

it('una empresa con solo la venta rápida no entra al POS de mostrador', function (): void {
    conModulos(['quick_pos']);

    $this->actingAs($this->owner)->get(route('panel.pos'))->assertForbidden();
});

it('sin ninguno de los dos, el terminal está cerrado', function (): void {
    conModulos(['inventory']);

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))->assertForbidden();
    $this->actingAs($this->owner)->post(route('panel.pos.open'), ['opening_amount' => '1000'])->assertForbidden();
});

it('una empresa sin lista de módulos los tiene todos, incluido el nuevo', function (): void {
    // `modules = NULL` significa «plan completo»: el módulo nuevo entra por definición.
    conModulos([]);
    $this->company->update(['modules' => null]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)->get(route('panel.quick-pos.index'))->assertOk();
});
