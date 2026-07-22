<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Presupuesto de consultas por página.
 *
 * Estas pruebas son una RED DE REGRESIÓN: cuentan las consultas SQL reales de una petición y fallan
 * si se disparan. Nacieron porque el menú y las tarjetas del panel llamaban a Company::hasModule()
 * una vez por elemento y cada llamada re-consultaba la suscripción (decenas de consultas idénticas).
 *
 * Si un cambio futuro reintroduce ese patrón (N+1), este test lo caza antes de llegar a producción.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Perf Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@perf.test', 'password' => 'secret-password',
    ]), 'owner');

    // Con suscripción activa: es el caso real y el que dispara las consultas de plan/módulos.
    $plan = Plan::create([
        'name' => 'Full', 'slug' => 'full', 'price' => '1000', 'billing_cycle' => 'monthly',
        'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
    Subscription::create([
        'company_id' => $this->company->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => Carbon::now()->subDay(),
        'current_period_end' => Carbon::now()->addMonth(),
    ]);

    Product::create(['sku' => 'P1', 'name' => 'Producto', 'cost' => '10', 'price' => '50']);
});

/**
 * Cuenta las consultas SQL que ejecuta una petición.
 */
function countQueries(callable $request): int
{
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $request();

    return $queries;
}

it('el dashboard se mantiene dentro de su presupuesto de consultas', function (): void {
    $count = countQueries(fn () => $this->actingAs($this->owner)->get(route('dashboard'))->assertOk());

    // Tope holgado sobre el valor real tras optimizar. Si vuelve a dispararse, es un N+1.
    expect($count)->toBeLessThan(25, "El dashboard ejecutó {$count} consultas.");
});

it('el POS se mantiene dentro de su presupuesto de consultas', function (): void {
    $count = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.pos'))->assertOk());

    expect($count)->toBeLessThan(25, "El POS ejecutó {$count} consultas.");
});

it('el inventario se mantiene dentro de su presupuesto de consultas', function (): void {
    $count = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.products'))->assertOk());

    expect($count)->toBeLessThan(25, "El inventario ejecutó {$count} consultas.");
});

it('en régimen normal el dashboard baja a un puñado de consultas', function (): void {
    // 1.ª carga: calcula el resumen ejecutivo (7 agregaciones) y las alertas, y los cachea.
    $first = countQueries(fn () => $this->actingAs($this->owner)->get(route('dashboard'))->assertOk());

    // 2.ª carga dentro del minuto: resumen y alertas ya no tocan la base. Este es el caso REAL
    // (el usuario navega y refresca muchas veces por minuto) y donde se ahorran los recursos.
    $second = countQueries(fn () => $this->actingAs($this->owner)->get(route('dashboard'))->assertOk());

    expect($second)->toBeLessThan($first, "1.ª={$first} consultas, 2.ª={$second} consultas.")
        ->and($second)->toBeLessThan(10, "La 2.ª carga ejecutó {$second} consultas.");
});

it('la campana de alertas se sirve de caché entre páginas', function (): void {
    // La campana está en el layout: sin caché, sus 4 consultas se repetirían en CADA página.
    $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

    $second = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.products'))->assertOk());

    expect($second)->toBeLessThan(12, "La 2.ª página ejecutó {$second} consultas.");
});
