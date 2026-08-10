<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Enlace entre cada plan y su producto en la pasarela de cobro.
 *
 * Es el primer eslabón para cobrar suscripciones en línea: cuando llegue el aviso de un pago, Polar
 * dirá QUÉ PRODUCTO se compró, y de esta correspondencia depende saber QUÉ PLAN activar. Si dos
 * planes apuntaran al mismo producto, esa traducción sería ambigua y se activaría el equivocado.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plataforma'));
    app(CurrentCompany::class)->set($this->company->id);

    // Solo el operador de la plataforma administra los planes.
    $this->super = User::create([
        'company_id' => $this->company->id, 'name' => 'Operador',
        'email' => 'super@planes.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);
});

/** Datos mínimos válidos para el formulario de plan. */
function datosPlan(array $extra = []): array
{
    return array_merge([
        'name' => 'Profesional',
        'slug' => 'profesional',
        'price' => '1500',
        'billing_cycle' => 'monthly',
        'trial_days' => 15,
        'modules' => ['pos', 'inventory'],
    ], $extra);
}

it('guarda el producto de Polar al crear el plan', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.plans.store'), datosPlan(['polar_product_id' => 'prod_abc123']))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Plan::firstWhere('slug', 'profesional')->polar_product_id)->toBe('prod_abc123');
});

it('el plan sin producto enlazado no es contratable en línea', function (): void {
    // Sigue existiendo y se asigna a mano, pero no se le puede poner un botón de pago: no habría
    // forma de saber qué activar cuando llegara el aviso de cobro.
    $this->actingAs($this->super)
        ->post(route('platform.plans.store'), datosPlan())
        ->assertSessionHasNoErrors();

    $plan = Plan::firstWhere('slug', 'profesional');

    expect($plan->polar_product_id)->toBeNull()
        ->and($plan->isPurchasable())->toBeFalse();
});

it('un plan enlazado y activo sí es contratable', function (): void {
    $plan = Plan::create(datosPlan([
        'polar_product_id' => 'prod_abc123', 'is_active' => true, 'modules' => null,
    ]));

    expect($plan->isPurchasable())->toBeTrue();
});

it('un plan enlazado pero desactivado NO es contratable', function (): void {
    // Desactivar un plan tiene que retirarlo de la venta, aunque su producto siga en Polar.
    $plan = Plan::create(datosPlan([
        'polar_product_id' => 'prod_abc123', 'is_active' => false, 'modules' => null,
    ]));

    expect($plan->isPurchasable())->toBeFalse();
});

it('rechaza enlazar dos planes al mismo producto', function (): void {
    Plan::create(datosPlan(['slug' => 'uno', 'polar_product_id' => 'prod_abc123', 'modules' => null]));

    // Con dos planes apuntando al mismo producto, un pago no diría cuál activar.
    $this->actingAs($this->super)
        ->post(route('platform.plans.store'), datosPlan([
            'slug' => 'dos', 'polar_product_id' => 'prod_abc123',
        ]))
        ->assertSessionHasErrors('polar_product_id');
});

it('editar un plan sin cambiar su producto no choca consigo mismo', function (): void {
    $plan = Plan::create(datosPlan(['polar_product_id' => 'prod_abc123', 'modules' => null]));

    $this->actingAs($this->super)
        ->put(route('platform.plans.update', $plan), datosPlan([
            'name' => 'Profesional renombrado', 'polar_product_id' => 'prod_abc123',
        ]))
        ->assertSessionHasNoErrors();

    expect($plan->fresh()->name)->toBe('Profesional renombrado');
});

it('traduce un producto de Polar al plan que le corresponde', function (): void {
    // Es la búsqueda que hará el webhook: Polar dice el producto, esto dice el plan.
    $plan = Plan::create(datosPlan(['polar_product_id' => 'prod_abc123', 'modules' => null]));

    expect(Plan::forPolarProduct('prod_abc123')?->id)->toBe($plan->id)
        ->and(Plan::forPolarProduct('prod_desconocido'))->toBeNull();
});

it('el formulario de planes muestra el campo del producto', function (): void {
    $this->actingAs($this->super)->get(route('platform.plans'))
        ->assertOk()
        ->assertSee('polar_product_id', false);
});
