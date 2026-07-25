<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => app(CurrentCompany::class)->forget());

/**
 * Crea una empresa con una suscripción de prueba (o del estado dado) y su marca de purga.
 */
function trialCompany(string $name, ?Carbon $purgeAt, SubscriptionStatus $status = SubscriptionStatus::Trialing): Company
{
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: $name));

    Subscription::create([
        'company_id' => $company->id,
        'plan_id' => null,
        'status' => $status,
        'trial_ends_at' => Carbon::now()->subDays(2),
        'purge_at' => $purgeAt,
    ]);

    return $company;
}

/**
 * Da de alta un cliente dentro de la empresa indicada (usa el tenant activo).
 */
function seedCustomer(Company $company, string $name): void
{
    app(CurrentCompany::class)->set($company->id);
    Customer::create(['name' => $name]);
    app(CurrentCompany::class)->forget();
}

it('purga los datos de una prueba vencida y conserva la cuenta', function (): void {
    $company = trialCompany('Vencida', Carbon::now()->subHour());

    app(CurrentCompany::class)->set($company->id);
    Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '20']);
    Customer::create(['name' => 'Cliente Prueba']);
    app(CurrentCompany::class)->forget();

    Artisan::call('trials:purge');

    // Datos de negocio borrados...
    expect(DB::table('products')->where('company_id', $company->id)->count())->toBe(0)
        ->and(DB::table('customers')->where('company_id', $company->id)->count())->toBe(0);

    // ...pero la cuenta (empresa + sucursal) se conserva y no se vuelve a purgar.
    expect(Company::find($company->id))->not->toBeNull()
        ->and(DB::table('branches')->where('company_id', $company->id)->count())->toBeGreaterThan(0)
        ->and(Subscription::where('company_id', $company->id)->first()->purge_at)->toBeNull();
});

it('no purga pruebas con fecha futura ni suscripciones activas', function (): void {
    $futura = trialCompany('Futura', Carbon::now()->addDays(5));
    seedCustomer($futura, 'Cliente Futura');

    // purge_at en el pasado pero ya activa (pagó) → intocable.
    $activa = trialCompany('Activa', Carbon::now()->subHour(), SubscriptionStatus::Active);
    seedCustomer($activa, 'Cliente Activa');

    Artisan::call('trials:purge');

    expect(DB::table('customers')->where('company_id', $futura->id)->count())->toBe(1)
        ->and(DB::table('customers')->where('company_id', $activa->id)->count())->toBe(1);
});

it('purgar una empresa no afecta los datos de otra', function (): void {
    $a = trialCompany('A', Carbon::now()->subHour());       // se purga
    $b = trialCompany('B', null);                            // prueba de operador (sin purga)

    seedCustomer($a, 'De A');
    seedCustomer($b, 'De B');

    Artisan::call('trials:purge');

    expect(DB::table('customers')->where('company_id', $a->id)->count())->toBe(0)
        ->and(DB::table('customers')->where('company_id', $b->id)->count())->toBe(1);
});

it('el endpoint de cron exige el secreto correcto', function (): void {
    config(['services.cron.secret' => 'topsecret']);

    $this->get('/tareas/purgar-pruebas')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer incorrecto')->get('/tareas/purgar-pruebas')->assertForbidden();

    $this->withHeader('Authorization', 'Bearer topsecret')
        ->get('/tareas/purgar-pruebas')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('el endpoint de cron se bloquea si no hay secreto configurado', function (): void {
    config(['services.cron.secret' => null]);

    $this->withHeader('Authorization', 'Bearer loquesea')->get('/tareas/purgar-pruebas')->assertForbidden();
});
