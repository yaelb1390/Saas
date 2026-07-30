<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Enums\SubscriptionStatus;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Mail\SubscriptionExpiringMail;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use App\Modules\Core\Models\Subscription;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
});

/** Plan mensual (umbral de aviso = 5 días). */
function mensualPlan(): Plan
{
    return Plan::firstOrCreate(['slug' => 'mensual'], [
        'name' => 'Mensual', 'price' => '1000', 'billing_cycle' => 'monthly',
        'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
}

/** Empresa con dueño y una suscripción activa que vence en $daysLeft días. */
function activeSubCompany(int $daysLeft, string $ownerEmail): Company
{
    app(CurrentCompany::class)->forget();
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Co '.Str::random(5)));
    User::create([
        'company_id' => $company->id, 'name' => 'Dueño', 'email' => $ownerEmail,
        'password' => bcrypt('secret-password'), 'is_active' => true,
    ]);
    Subscription::create([
        'company_id' => $company->id, 'plan_id' => mensualPlan()->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->addDays($daysLeft),
    ]);
    app(CurrentCompany::class)->forget();

    return $company;
}

it('envía la confirmación al registrar un pago', function (): void {
    $company = activeSubCompany(3, 'dueno@co.test');
    $super = User::create([
        'name' => 'Super', 'email' => 'super@bm.test', 'password' => bcrypt('secret-password'),
        'is_super_admin' => true, 'is_active' => true,
    ]);

    Mail::fake();

    $this->actingAs($super)
        ->post(route('platform.companies.payment', $company))
        ->assertRedirect();

    Mail::assertQueued(SubscriptionConfirmedMail::class, fn (SubscriptionConfirmedMail $m): bool => $m->hasTo('dueno@co.test') && $m->planName === 'Mensual');
});

it('el comando avisa a las suscripciones de pago por vencer (dentro del umbral)', function (): void {
    activeSubCompany(3, 'porvencer@co.test');   // dentro del umbral (5 días)
    activeSubCompany(20, 'lejos@co.test');       // fuera del umbral

    Mail::fake();
    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertQueued(SubscriptionExpiringMail::class, 1);
    Mail::assertQueued(SubscriptionExpiringMail::class, fn (SubscriptionExpiringMail $m): bool => $m->hasTo('porvencer@co.test'));
});

it('no avisa a una prueba por vencer', function (): void {
    $company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Prueba Co'));
    User::create(['company_id' => $company->id, 'name' => 'X', 'email' => 'prueba@co.test', 'password' => bcrypt('secret-password'), 'is_active' => true]);
    Subscription::create([
        'company_id' => $company->id, 'plan_id' => mensualPlan()->id,
        'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(2),
    ]);
    app(CurrentCompany::class)->forget();

    Mail::fake();
    $this->artisan('subscriptions:remind-expiring')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('no reenvía el aviso en la segunda corrida (dedup)', function (): void {
    activeSubCompany(3, 'dedup@co.test');

    Mail::fake();
    $this->artisan('subscriptions:remind-expiring');
    Mail::assertQueued(SubscriptionExpiringMail::class, 1);

    $this->artisan('subscriptions:remind-expiring');
    Mail::assertQueued(SubscriptionExpiringMail::class, 1); // sigue en 1: no reenvía
});
