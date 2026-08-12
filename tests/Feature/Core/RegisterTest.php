<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Alta self-service: cuatro campos y dentro.
 *
 * El alta NO pregunta el plan. Entra todo el mundo por el de configuración y lo cambia luego desde
 * su panel. Comparar precios en mitad de un formulario es pedir una decisión que el cliente aún no
 * puede tomar, justo donde más gente abandona.
 *
 * Lo que se cubre aquí es sobre todo que el alta no se pueda quedar a medias: una empresa creada sin
 * suscripción sería un cliente dentro del sistema al que nadie sabría qué cobrarle.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    config(['bmos.registration.default_plan_slug' => 'basico']);

    $this->basico = Plan::create([
        'name' => 'Básico', 'slug' => 'basico', 'price' => '750',
        'billing_cycle' => 'monthly', 'trial_days' => 0,
        'modules' => ['pos', 'inventory'], 'is_active' => true,
    ]);

    $this->completo = Plan::create([
        'name' => 'Empresarial', 'slug' => 'empresarial', 'price' => '3000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
});

/**
 * Datos válidos del formulario.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function altaValida(array $extra = []): array
{
    return array_merge([
        'company_name' => 'Mi Negocio',
        'owner_name' => 'Juan Dueño',
        'owner_email' => 'juan@negocio.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ], $extra);
}

it('muestra el formulario de registro sin pedir plan', function (): void {
    $this->get('/registro')
        ->assertOk()
        ->assertSee('Crea tu cuenta')
        ->assertDontSee('¿Qué plan quieres probar?')
        ->assertSee('Comparar planes');
});

it('registra la empresa en el plan de entrada', function (): void {
    $this->post('/registro', altaValida())->assertRedirect(route('dashboard'));

    $this->assertAuthenticated();

    $user = User::where('email', 'juan@negocio.test')->firstOrFail();
    $company = Company::findOrFail($user->company_id);
    $sub = Subscription::where('company_id', $company->id)->firstOrFail();

    expect($company->name)->toBe('Mi Negocio')
        ->and($sub->plan_id)->toBe($this->basico->id)
        ->and($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue()
        ->and($sub->purge_at->greaterThan($sub->trial_ends_at))->toBeTrue(); // 24 h después
});

it('la empresa hereda los módulos del plan en vez de congelar una copia', function (): void {
    // `company.modules` en NULL es lo que hace que, si el plan gana un módulo mañana, esta empresa
    // lo reciba sola. Y es también lo que hace que cambiar de plan cambie de verdad lo que ve.
    $this->post('/registro', altaValida());

    $company = Company::firstWhere('name', 'Mi Negocio');

    expect($company->modules)->toBeNull()
        ->and($company->activeModules())->toBe(['pos', 'inventory']);
});

it('si el plan de entrada está inactivo cae en el activo más barato', function (): void {
    // Un catálogo mal configurado no puede dejar sin alta a alguien que ya rellenó el formulario.
    $this->basico->update(['is_active' => false]);

    $this->post('/registro', altaValida())->assertRedirect(route('dashboard'));

    expect(Subscription::firstOrFail()->plan_id)->toBe($this->completo->id);
});

it('sin ningún plan activo avisa y no deja una empresa a medias', function (): void {
    // El peor final posible sería una empresa creada y sin suscripción: un cliente dentro del
    // sistema al que nadie sabría qué cobrarle.
    Plan::query()->update(['is_active' => false]);

    $this->post('/registro', altaValida())->assertRedirect();

    expect(Company::count())->toBe(0)
        ->and(User::where('email', 'juan@negocio.test')->count())->toBe(0)
        ->and(session('panel_error'))->toContain('No hay planes disponibles');
});

it('la prueba dura lo que dice la configuración, no lo que diga el plan', function (): void {
    config(['bmos.trial.days' => 15]);
    $this->basico->update(['trial_days' => 3]);

    $this->post('/registro', altaValida());

    expect((int) round(now()->diffInDays(Subscription::firstOrFail()->trial_ends_at, false)))->toBe(15);
});

it('rechaza un correo ya registrado', function (): void {
    User::create(['name' => 'Existente', 'email' => 'dup@x.test', 'password' => 'secret', 'is_active' => true]);

    $this->post('/registro', altaValida(['owner_email' => 'dup@x.test']))
        ->assertSessionHasErrors('owner_email');
});
