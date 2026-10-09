<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\SubscriptionService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/*
 * «Cuenta suspendida» con botón para pagar y seguir.
 *
 * Se ofrece SOLO cuando la cuenta se paró por falta de pago (plan vencido o cancelado) y a quien
 * puede pagar (el propietario). Si el operador la suspendió a mano o desactivó la empresa, pagar no
 * es la salida: solo contacto, para que nadie se salte esa decisión pagando.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    Http::preventStrayRequests();
    config(['polar.access_token' => 'polar_oat_de_prueba', 'polar.server' => 'sandbox']);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Variedades', email: 'duena@variedades.example.com',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@variedades.example.com', 'password' => 'secret-password',
    ]), 'owner');

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 0, 'modules' => null, 'is_active' => true,
        'polar_product_id' => 'ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe',
    ]);

    $this->sub = app(SubscriptionService::class)->subscribe($this->company, $this->plan);
    // Venció: falta de pago.
    $this->sub->forceFill(['trial_ends_at' => now()->subDay(), 'current_period_end' => now()->subDay()])->save();
});

it('al propietario con el plan vencido le ofrece pagar y reactivar', function (): void {
    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertOk()
        ->assertSee('¿Quieres seguir?')
        ->assertSee('Pagar y reactivar')
        ->assertSee('RD$ 1,500.00')
        ->assertSee('action="'.route('panel.account.checkout', $this->plan).'"', false)
        ->assertSee(route('plans.public'), false);
});

it('el botón lleva al cobro de Polar', function (): void {
    Http::fake(['*/v1/checkouts/' => Http::response(['id' => 'chk_1', 'url' => 'https://sandbox.polar.sh/checkout/polar_c_abc'], 201)]);

    $this->actingAs($this->owner)
        ->from(route('panel.suspended'))
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect('https://sandbox.polar.sh/checkout/polar_c_abc');
});

it('si Polar no abre el cobro, el error se ve en la pantalla de suspensión', function (): void {
    Http::fake(['*/v1/checkouts/' => Http::response(['detail' => 'caído'], 500)]);

    $this->actingAs($this->owner)
        ->from(route('panel.suspended'))
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect(route('panel.suspended'));

    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertSee('class="alert-error"', false);
});

it('a un empleado no le muestra el botón: le pide avisar al propietario', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@variedades.example.com', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.suspended'))
        ->assertOk()
        ->assertDontSee('Pagar y reactivar')
        ->assertSee('Pídele al propietario de la cuenta');
});

it('si la suspendió el operador, no ofrece pagar', function (): void {
    app(SubscriptionService::class)->suspend($this->sub->fresh());

    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertOk()
        ->assertDontSee('Pagar y reactivar')
        ->assertSee('fue suspendido por el administrador');
});

it('si la empresa está desactivada, no ofrece pagar', function (): void {
    $this->company->update(['is_active' => false]);

    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertOk()
        ->assertDontSee('Pagar y reactivar')
        ->assertSee('está desactivada');
});

it('un plan que no se puede cobrar en línea solo ofrece contacto', function (): void {
    $this->plan->update(['polar_product_id' => null]);

    $this->actingAs($this->owner)->get(route('panel.suspended'))
        ->assertOk()
        ->assertDontSee('Pagar y reactivar')
        ->assertSee('comunícate con el administrador');
});
