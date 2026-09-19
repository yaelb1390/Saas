<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Dealer\DTOs\CreateVehicleData;
use App\Modules\Dealer\Services\VehicleService;
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

    // Valor real en frío: 25 consultas. Llegó a 34 cuando el dashboard incorporó la cartera de
    // préstamos y sus gráficos; consolidar las agregaciones repetidas (varias recorrían la misma
    // tabla filtrando por estado) lo devolvió a 25, pero ya no cabe bajo el tope anterior, que era
    // justo 25. No es un N+1: no queda ninguna consulta repetida salvo las dos que la campana de
    // alertas comparte a propósito con el resumen (se cachean aparte porque la campana se pinta en
    // todas las páginas). El tope se deja holgado para seguir cazando lo que importa: la repetición
    // por elemento, que se cuenta por decenas y no por unidades.
    //
    // Subió a 30 al añadir el aviso de ventas cobradas sin conexión, y el tope pasó de 30 a 33. Las
    // tres consultas nuevas son: el conteo de ventas por revisar (1, real en cada cálculo) y las dos
    // con las que DbTable comprueba que la columna `offline_review` ya existe —el despliegue puede ir
    // por delante de la migración, ver DbTable—.
    //
    // Esas DOS no se pagan en cada petición: el memo de DbTable es estático y en PHP-FPM vive lo que
    // vive el proceso trabajador, así que se hacen una vez y sirven a miles de peticiones. Aquí se
    // ven porque el test arranca en frío, que es justo lo que tiene que medir.
    expect($count)->toBeLessThan(33, "El dashboard ejecutó {$count} consultas.");
});

it('el POS se mantiene dentro de su presupuesto de consultas', function (): void {
    $count = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.pos'))->assertOk());

    expect($count)->toBeLessThan(25, "El POS ejecutó {$count} consultas.");
});

it('el inventario se mantiene dentro de su presupuesto de consultas', function (): void {
    $count = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.products'))->assertOk());

    // Valor real en frío: 27 consultas. Subió de 25 cuando la pantalla incorporó las cuatro
    // tarjetas de resumen (agregaciones sobre TODO el catálogo, no la página de 15 que se ve): no
    // es un N+1, son cuatro consultas nuevas y necesarias que solo se pagan en frío (ver el test
    // «en régimen normal» de abajo, que sí se ahorran en la segunda carga gracias a la caché).
    expect($count)->toBeLessThan(30, "El inventario ejecutó {$count} consultas.");
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

    // Subió de 12 a 13 por la misma razón que el test de arriba: las tarjetas de resumen del
    // inventario. La campana sigue sirviéndose de caché (por eso el tope sigue holgado y no en 27).
    expect($second)->toBeLessThan(15, "La 2.ª página ejecutó {$second} consultas.");
});

it('en régimen normal el inventario baja a un puñado de consultas', function (): void {
    // 1.ª carga: calcula el resumen (4 agregaciones sobre todo el catálogo) y lo cachea.
    $first = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.products'))->assertOk());

    // 2.ª carga dentro del minuto: el resumen ya no toca la base (ver ProductSummaryService).
    $second = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.products'))->assertOk());

    expect($second)->toBeLessThan($first, "1.ª={$first} consultas, 2.ª={$second} consultas.");
});

it('en régimen normal el alquiler baja a un puñado de consultas', function (): void {
    $this->company->update(['modules' => ['dealer', 'rental', 'crm']]);
    app(VehicleService::class)->create(new CreateVehicleData(
        make: 'Toyota', model: 'Corolla', year: 2022,
        purchaseCost: '900000', askingPrice: '1200000',
        usageType: 'both', rentalPriceDaily: '3500', depositAmount: '10000',
        rentalKmLimitDaily: 200, extraKmPrice: '15',
    ));

    // 1.ª carga: calcula el resumen de la flota (varias agregaciones) y lo cachea.
    $first = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.rentals'))->assertOk());

    // 2.ª carga dentro del minuto: el resumen ya no toca la base (ver VehicleRentalReportService).
    $second = countQueries(fn () => $this->actingAs($this->owner)->get(route('panel.rentals'))->assertOk());

    expect($second)->toBeLessThan($first, "1.ª={$first} consultas, 2.ª={$second} consultas.");
});
