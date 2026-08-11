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
 * Botón de pago de la suscripción.
 *
 * Abre el cobro en la pasarela y manda al cliente allí. Lo que NO hace —y es lo importante— es
 * activar nada: eso lo hace el aviso de pago de Polar cuando el dinero entra de verdad. La
 * dirección de retorno la puede escribir cualquiera en la barra del navegador, así que activar al
 * volver sería regalar el plan a quien la teclee.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    config(['polar.access_token' => 'polar_oat_de_prueba', 'polar.server' => 'sandbox']);

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Heladería', email: 'duena@heladeria.example.com',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.example.com', 'password' => 'secret-password',
    ]), 'owner');

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => '1500', 'billing_cycle' => 'monthly',
        'trial_days' => 15, 'modules' => null, 'is_active' => true,
        'polar_product_id' => 'ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe',
    ]);

    app(SubscriptionService::class)->subscribe($this->company, $this->plan);
});

/** Respuesta de Polar cuando el cobro se abre bien. */
function polarAbreCobro(string $url = 'https://sandbox.polar.sh/checkout/polar_c_abc'): void
{
    Http::fake(['*/v1/checkouts/' => Http::response(['id' => 'chk_1', 'url' => $url], 201)]);
}

it('el botón lleva a la pasarela de pago', function (): void {
    polarAbreCobro();

    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect('https://sandbox.polar.sh/checkout/polar_c_abc');
});

it('adjunta la empresa al cobro, que es como se sabe a quién activar', function (): void {
    // Sin este dato, el aviso de pago tendría que adivinar la empresa por el correo del comprador.
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    Http::assertSent(function ($request): bool {
        $cuerpo = $request->data();

        return $cuerpo['metadata']['company_id'] === (string) $this->company->id
            && $cuerpo['products'] === ['ad5bee12-beb1-48ee-b6ec-1eb5c9d1b6fe'];
    });
});

it('abrir el cobro NO activa la suscripción', function (): void {
    // Solo se abre la puerta. Quien confirma el dinero es el webhook.
    polarAbreCobro();

    $this->actingAs($this->owner)->post(route('panel.account.checkout', $this->plan));

    expect($this->company->subscription->fresh()->isTrialing())->toBeTrue();
});

it('volver de la pasarela tampoco activa nada', function (): void {
    // Esta dirección se puede teclear a mano: si activara, el plan sería gratis para quien la sepa.
    $this->actingAs($this->owner)
        ->get(route('panel.account', ['pago' => 'recibido']))
        ->assertOk()
        ->assertSee('Estamos confirmándolo', false);

    expect($this->company->subscription->fresh()->isTrialing())->toBeTrue();
});

it('no ofrece pagar un plan sin enlazar con la pasarela', function (): void {
    $this->plan->update(['polar_product_id' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Activar mi plan');

    // Y tampoco por la puerta de atrás: ocultar el botón no basta.
    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect();

    expect(session('panel_error'))->toContain('no se puede contratar en línea');
});

it('sin pasarela configurada la pantalla solo ofrece contacto', function (): void {
    config(['polar.access_token' => null]);

    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertDontSee('Activar mi plan');
});

it('avisa en vez de romperse si la pasarela falla', function (): void {
    // Un fallo de Polar no puede dejar al cliente ante un error 500 sin saber qué hacer.
    Http::fake(['*/v1/checkouts/' => Http::response(['detail' => 'algo falló'], 422)]);

    $this->actingAs($this->owner)
        ->post(route('panel.account.checkout', $this->plan))
        ->assertRedirect();

    expect(session('panel_error'))->toContain('No pudimos abrir la pasarela');
});

it('un cajero no puede iniciar el pago de la empresa', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.example.com', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.account.checkout', $this->plan))->assertForbidden();
});

it('la pantalla muestra el botón con el precio del plan', function (): void {
    $this->actingAs($this->owner)->get(route('panel.account'))
        ->assertOk()
        ->assertSee('Activar mi plan')
        ->assertSee('1,500.00');
});
