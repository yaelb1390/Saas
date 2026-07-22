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

it('un cajero opera el punto de venta, su única pantalla de trabajo', function (): void {
    // El cajero solo administra la caja: cobra en el POS. No necesita (ni ve) el resto del sistema.
    $this->actingAs($this->cajero)->get(route('panel.pos'))->assertOk();
});

it('un cajero no entra a las pantallas que no le corresponden', function (): void {
    // Solo tiene caja/POS: inventario, facturas, RRHH y finanzas le están vedados aunque escriba
    // la URL a mano.
    $this->actingAs($this->cajero)->get(route('panel.products'))->assertForbidden();
    $this->actingAs($this->cajero)->get(route('panel.invoices'))->assertForbidden();
    $this->actingAs($this->cajero)->get(route('panel.employees'))->assertForbidden();
    $this->actingAs($this->cajero)->get(route('panel.finance'))->assertForbidden();
});

it('el menú lateral solo muestra la caja al cajero', function (): void {
    $response = $this->actingAs($this->cajero)->get(route('dashboard'))->assertOk();

    // Ve el enlace del POS…
    $response->assertSee(route('panel.pos'));

    // …y ninguna pantalla de gestión se dibuja para él.
    $response->assertDontSee(route('panel.products'))
        ->assertDontSee(route('panel.invoices'))
        ->assertDontSee(route('panel.employees'))
        ->assertDontSee(route('panel.finance'));
});

it('solo el propietario accede a la suscripción; el administrador no', function (): void {
    // La facturación y el plan son del dueño (company.manage). Un administrador gestiona la
    // operación, pero no toca la suscripción de la empresa.
    $admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin',
        'email' => 'admin@perm.test', 'password' => 'secret-password',
    ]), 'admin');

    $this->actingAs($this->owner)->get(route('panel.account'))->assertOk();
    $this->actingAs($admin)->get(route('panel.account'))->assertForbidden();
    $this->actingAs($this->cajero)->get(route('panel.account'))->assertForbidden();
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
