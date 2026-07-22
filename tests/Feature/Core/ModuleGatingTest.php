<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plan Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@plan.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->super = User::create([
        'company_id' => null, 'is_super_admin' => true,
        'name' => 'Super', 'email' => 'super@plan.test', 'password' => 'secret-password',
    ]);
});

it('con el plan completo (modules NULL) el dueño entra a todos los módulos', function (): void {
    expect($this->company->modules)->toBeNull();

    $this->actingAs($this->owner)->get(route('panel.invoices'))->assertOk();
    $this->actingAs($this->owner)->get(route('panel.ai'))->assertOk();
});

it('un módulo fuera del plan queda bloqueado aunque el dueño tenga el permiso', function (): void {
    // Plan sin Facturación ni IA.
    $this->company->update(['modules' => ['pos', 'sales', 'inventory']]);

    $this->actingAs($this->owner)->get(route('panel.invoices'))->assertForbidden();
    $this->actingAs($this->owner)->get(route('panel.ai'))->assertForbidden();

    // Lo que sí está en el plan sigue abierto.
    $this->actingAs($this->owner)->get(route('panel.pos'))->assertOk();
});

it('las acciones del módulo bloqueado también responden 403', function (): void {
    $this->company->update(['modules' => ['pos']]);

    $this->actingAs($this->owner)
        ->post(route('panel.products.store'), ['sku' => 'X', 'name' => 'Y', 'price' => '1'])
        ->assertForbidden();
});

it('el super admin atraviesa el gating de módulos', function (): void {
    $this->company->update(['modules' => ['pos']]);
    // El super admin opera sobre la empresa activa por sesión.
    $this->actingAs($this->super)->withSession(['active_company_id' => $this->company->id])
        ->get(route('panel.invoices'))->assertOk();
});

it('el menú oculta los módulos que la empresa no contrató', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);

    $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('panel.pos'))
        ->and($html)->not->toContain(route('panel.invoices'))
        ->and($html)->not->toContain(route('panel.ai'));
});

// ---------------------------------------------------------------- Panel de plataforma

it('el super admin ve el panel de empresas; un dueño no', function (): void {
    $this->actingAs($this->super)->get(route('platform.companies'))->assertOk();
    $this->actingAs($this->owner)->get(route('platform.companies'))->assertForbidden();
});

it('el super admin ajusta el plan de una empresa', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['modules' => ['pos', 'sales', 'billing']])
        ->assertRedirect();

    expect($this->company->fresh()->modules)->toBe(['pos', 'sales', 'billing']);
});

it('seleccionar todos los módulos guarda el plan completo (NULL)', function (): void {
    $this->company->update(['modules' => ['pos']]);

    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['modules' => ModuleRegistry::keys()])
        ->assertRedirect();

    expect($this->company->fresh()->modules)->toBeNull();
});

it('ignora claves de módulo inventadas', function (): void {
    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['modules' => ['pos', 'modulo-fantasma']])
        ->assertSessionHasErrors('modules.1');
});

it('el super admin suspende y reactiva una empresa', function (): void {
    $this->actingAs($this->super)
        ->post(route('platform.companies.toggle', $this->company))
        ->assertRedirect();

    expect($this->company->fresh()->is_active)->toBeFalse();
});

// ---------------------------------------------------------------- Override de módulos con plan

/**
 * Suscribe la empresa activa a un plan con los módulos indicados (NULL = plan completo).
 *
 * @param  array<int, string>|null  $modules
 */
function subscribeCompanyTo(?array $modules): void
{
    $plan = Plan::create([
        'name' => 'Plan Test', 'slug' => 'plan-test', 'price' => '1000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => $modules, 'is_active' => true,
    ]);

    Subscription::create([
        'company_id' => test()->company->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::now()->subDay(),
        'current_period_end' => Carbon::now()->addMonth(),
    ]);
}

it('con suscripción y sin ajuste manual, la empresa hereda los módulos del plan', function (): void {
    subscribeCompanyTo(['pos', 'sales']); // plan limitado

    $company = $this->company->fresh();

    expect($company->modules)->toBeNull()
        ->and($company->activeModules())->toBe(['pos', 'sales'])
        ->and($company->hasModule('billing'))->toBeFalse();
});

it('el ajuste manual (override) sustituye a los módulos del plan para esa empresa', function (): void {
    subscribeCompanyTo(['pos', 'sales']); // el plan no incluye Facturación...

    // ...pero el operador se la habilita solo a esta empresa.
    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['modules' => ['pos', 'billing']])
        ->assertRedirect();

    $company = $this->company->fresh();

    expect($company->modules)->toBe(['pos', 'billing'])
        ->and($company->hasModule('billing'))->toBeTrue()
        ->and($company->hasModule('sales'))->toBeFalse();

    // Y el gating de rutas respeta el override.
    $this->actingAs($this->owner)->get(route('panel.invoices'))->assertOk();
});

it('«volver al plan» borra el ajuste manual y la empresa vuelve a heredar del plan', function (): void {
    subscribeCompanyTo(['pos', 'sales']);
    $this->company->update(['modules' => ['pos']]); // ajuste manual previo

    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['follow_plan' => '1'])
        ->assertRedirect();

    expect($this->company->fresh()->modules)->toBeNull();
});

it('guardar exactamente los módulos del plan se traduce en «seguir el plan» (NULL)', function (): void {
    subscribeCompanyTo(['pos', 'sales']);
    $this->company->update(['modules' => ['pos']]); // partía de un override

    $this->actingAs($this->super)
        ->put(route('platform.companies.modules', $this->company), ['modules' => ['pos', 'sales']])
        ->assertRedirect();

    expect($this->company->fresh()->modules)->toBeNull();
});

it('una suscripción no usable no da acceso ni con ajuste manual', function (): void {
    subscribeCompanyTo(['pos', 'sales']);
    $this->company->update(['modules' => ['pos', 'billing']]);
    $this->company->subscription->update(['status' => SubscriptionStatus::Suspended]);

    $company = $this->company->fresh();

    expect($company->activeModules())->toBe([])
        ->and($company->hasModule('pos'))->toBeFalse();
});
