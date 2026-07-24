<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cartera Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@loans.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->customer = Customer::create(['name' => 'Cliente Uno', 'phone' => '18095550000']);
});

/**
 * @return array<string, mixed>
 */
function loanPayload(int $customerId): array
{
    return [
        'customer_id' => $customerId,
        'principal' => '10000',
        'interest_rate' => '20',
        'installments_count' => 4,
        'frequency' => LoanFrequency::Monthly->value,
        'start_date' => '2026-08-01',
    ];
}

it('el dueño crea un préstamo y se desembolsa', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.loans.store'), loanPayload($this->customer->id))
        ->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    $loan = Loan::firstOrFail();

    expect($loan->total)->toBe('12000.00')
        ->and($loan->installments)->toHaveCount(4);
});

it('registra un abono desde el panel y baja el saldo', function (): void {
    $this->actingAs($this->owner)->post(route('panel.loans.store'), loanPayload($this->customer->id));
    app(CurrentCompany::class)->set($this->company->id);
    $loan = Loan::firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('panel.loans.payments.store', $loan), ['amount' => '3000'])
        ->assertRedirect();

    expect($loan->refresh()->balance)->toBe('9000.00');
});

it('rechaza crear un préstamo sin capital', function (): void {
    $payload = loanPayload($this->customer->id);
    unset($payload['principal']);

    $this->actingAs($this->owner)
        ->post(route('panel.loans.store'), $payload)
        ->assertSessionHasErrors('principal');
});

it('un cajero no accede a préstamos', function (): void {
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@loans.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->get(route('panel.loans'))->assertForbidden();
    $this->actingAs($cajero)->post(route('panel.loans.store'), loanPayload($this->customer->id))->assertForbidden();
});

it('no muestra el préstamo de otra empresa', function (): void {
    $this->actingAs($this->owner)->post(route('panel.loans.store'), loanPayload($this->customer->id));
    app(CurrentCompany::class)->set($this->company->id);
    $loan = Loan::firstOrFail();

    // Un dueño de otra empresa no puede ver este préstamo (route binding aislado → 404).
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Tercera SRL'));
    $intruso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Intruso',
        'email' => 'intruso@loans.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($intruso)->get(route('panel.loans.show', $loan))->assertNotFound();
});

it('imprime el recibo de un cobro con monto y saldo adeudado', function (): void {
    $this->actingAs($this->owner)->post(route('panel.loans.store'), loanPayload($this->customer->id));
    app(CurrentCompany::class)->set($this->company->id);
    $loan = Loan::firstOrFail(); // total 12,000
    $this->actingAs($this->owner)->post(route('panel.loans.payments.store', $loan), ['amount' => '3000']);
    app(CurrentCompany::class)->set($this->company->id);
    $payment = $loan->payments()->firstOrFail();

    $this->actingAs($this->owner)
        ->get(route('panel.loans.receipt', [$loan, $payment]))
        ->assertOk()
        ->assertSee('RECIBO DE COBRO')
        ->assertSee('9,000.00'); // saldo adeudado tras el abono
});

it('la página de préstamos muestra el resumen de cartera', function (): void {
    $this->actingAs($this->owner)->get(route('panel.loans'))
        ->assertOk()
        ->assertSee('Aprobados')
        ->assertSee('Pagados')
        ->assertSee('Cartera pendiente');
});
