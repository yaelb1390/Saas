<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Permisos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $make = fn (string $email, string $role): User => withRole(User::create([
        'company_id' => $this->company->id,
        'name' => ucfirst($role),
        'email' => $email,
        'password' => 'secret-password',
    ]), $role);

    $this->owner = $make('owner@perm.test', 'owner');
    $this->cajero = $make('cajero@perm.test', 'staff');

    $this->invoice = Invoice::create([
        'company_id' => $this->company->id, 'ncf' => 'B0200000001', 'type' => NcfType::Consumo,
        'subtotal' => '100', 'tax' => '18', 'total' => '118',
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
    ]);
});

it('un cajero NO puede anular un comprobante fiscal', function (): void {
    $this->actingAs($this->cajero)
        ->post(route('panel.invoices.cancel', $this->invoice), ['reason' => '05'])
        ->assertForbidden();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Issued);
});

it('el dueño sí puede anular un comprobante fiscal', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.invoices.cancel', $this->invoice), ['reason' => '05'])
        ->assertRedirect();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('un cajero no puede administrar el inventario ni las secuencias de NCF', function (): void {
    $this->actingAs($this->cajero)
        ->post(route('panel.products.store'), ['sku' => 'X1', 'name' => 'Contrabando', 'price' => '10'])
        ->assertForbidden();

    $this->actingAs($this->cajero)
        ->post(route('panel.sequences.store'), [
            'type' => 'B02', 'range_from' => 1, 'range_to' => 100,
            'number_length' => 8, 'expires_at' => now()->addYear()->toDateString(),
        ])
        ->assertForbidden();
});

it('un cajero sí opera el punto de venta y ve las pantallas de su trabajo', function (): void {
    $this->actingAs($this->cajero)->get(route('panel.pos'))->assertOk();
    $this->actingAs($this->cajero)->get(route('panel.invoices'))->assertOk();
    $this->actingAs($this->cajero)->get(route('panel.products'))->assertOk();
});

it('un cajero no entra a las pantallas que no le corresponden', function (): void {
    // RRHH y Finanzas no están entre sus permisos: la URL escrita a mano tampoco lo deja pasar.
    $this->actingAs($this->cajero)->get(route('panel.employees'))->assertForbidden();
    $this->actingAs($this->cajero)->get(route('panel.finance'))->assertForbidden();
});

it('el menú lateral solo muestra los módulos permitidos', function (): void {
    $response = $this->actingAs($this->cajero)->get(route('dashboard'))->assertOk();

    // Ve los enlaces de su trabajo…
    $response->assertSee(route('panel.pos'))->assertSee(route('panel.invoices'));

    // …y los que no puede abrir ni siquiera se dibujan. (Se comprueba el enlace, no el rótulo:
    // «Finanzas» también da nombre a la sección donde vive Facturación, que el cajero sí ve.)
    $response->assertDontSee(route('panel.employees'))
        ->assertDontSee(route('panel.finance'));
});

it('el super administrador atraviesa todos los permisos', function (): void {
    $super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@perm.test', 'password' => 'secret-password',
    ]);

    // Sin ningún rol asignado: pasa por Gate::before.
    $this->actingAs($super)->get(route('panel.employees'))->assertOk();
    $this->actingAs($super)->get(route('panel.finance'))->assertOk();
});

it('un usuario sin rol no entra a ninguna pantalla del panel', function (): void {
    $huerfano = User::create([
        'company_id' => $this->company->id,
        'name' => 'Sin rol', 'email' => 'sinrol@perm.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($huerfano)->get(route('dashboard'))->assertForbidden();
    $this->actingAs($huerfano)->get(route('panel.products'))->assertForbidden();
});
