<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Loans\DTOs\CreateLoanData;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Exceptions\LoanException;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Préstamos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->customer = Customer::create(['name' => 'Juan Cliente', 'phone' => '18095551234']);
    $this->account = Account::where('is_default', true)->firstOrFail();
    $this->loans = app(LoanService::class);
});

/**
 * Atajo para crear un préstamo con valores por defecto razonables.
 *
 * @param  array<string, mixed>  $o
 */
function loanFixture(array $o = []): Loan
{
    return test()->loans->create(new CreateLoanData(
        customerId: test()->customer->id,
        principal: $o['principal'] ?? '10000',
        installmentsCount: $o['count'] ?? 4,
        frequency: $o['frequency'] ?? LoanFrequency::Monthly,
        startDate: $o['startDate'] ?? '2026-08-01',
        interestRate: $o['rate'] ?? '20',
        interestAmount: $o['amount'] ?? null,
    ));
}

it('crea un préstamo con interés simple y cuotas correctas', function (): void {
    $loan = loanFixture();

    expect($loan->interest_amount)->toBe('2000.00')      // 20% de 10,000
        ->and($loan->total)->toBe('12000.00')
        ->and($loan->installment_amount)->toBe('3000.00') // 12,000 / 4
        ->and($loan->balance)->toBe('12000.00')
        ->and($loan->status)->toBe(LoanStatus::Active)
        ->and($loan->code)->toBe('PR-000001')
        ->and($loan->installments)->toHaveCount(4);

    // El calendario avanza por mes desde el primer vencimiento.
    expect($loan->installments[0]->due_date->toDateString())->toBe('2026-08-01')
        ->and($loan->installments[1]->due_date->toDateString())->toBe('2026-09-01');
});

it('el interés por monto manda sobre la tasa, y la frecuencia semanal espacia 7 días', function (): void {
    $loan = loanFixture(['principal' => '5000', 'count' => 2, 'frequency' => LoanFrequency::Weekly, 'rate' => '10', 'amount' => '1500']);

    expect($loan->interest_amount)->toBe('1500.00')
        ->and($loan->total)->toBe('6500.00')
        ->and($loan->installment_amount)->toBe('3250.00');

    expect($loan->installments[1]->due_date->toDateString())->toBe('2026-08-08');
});

it('registrar un abono baja el saldo y salda las cuotas por orden', function (): void {
    $loan = loanFixture();

    $this->loans->registerPayment($loan, '3000');
    $loan->refresh();

    expect($loan->balance)->toBe('9000.00')
        ->and($loan->installments()->first()->status->value)->toBe('paid')
        ->and($loan->installments()->where('number', 2)->first()->status->value)->toBe('pending');
});

it('el préstamo queda saldado al cubrir todo el saldo', function (): void {
    $loan = loanFixture(['principal' => '1000', 'count' => 1, 'rate' => '0']);

    $this->loans->registerPayment($loan, '1000');
    $loan->refresh();

    expect($loan->status)->toBe(LoanStatus::Paid)
        ->and($loan->balance)->toBe('0.00');
});

it('el desembolso registra un egreso y el cobro un ingreso en Finanzas', function (): void {
    $loan = loanFixture(['principal' => '10000', 'rate' => '0']);

    // Desembolso: salió el capital de la caja.
    expect($this->account->refresh()->balance)->toBe('-10000.00');
    expect(FinancialMovement::where('type', MovementType::Expense)->where('reference_id', $loan->id)->exists())->toBeTrue();

    $this->loans->registerPayment($loan, '4000');

    expect($this->account->refresh()->balance)->toBe('-6000.00');
    expect(FinancialMovement::where('type', MovementType::Income)->exists())->toBeTrue();
});

it('ajustar la mora de una cuota sube el saldo del préstamo', function (): void {
    $loan = loanFixture(['principal' => '1000', 'count' => 1, 'rate' => '0']);
    $installment = $loan->installments()->first();

    $this->loans->setInstallmentLateFee($loan, $installment, '100');

    expect($loan->refresh()->balance)->toBe('1100.00')
        ->and($installment->refresh()->late_fee)->toBe('100.00');
});

it('no permite un abono mayor al saldo', function (): void {
    $loan = loanFixture(['principal' => '1000', 'count' => 1, 'rate' => '0']);

    expect(fn () => $this->loans->registerPayment($loan, '1500'))->toThrow(LoanException::class);
});

it('congela el saldo del préstamo en cada cobro para el recibo', function (): void {
    $loan = loanFixture(); // total 12,000

    $p1 = $this->loans->registerPayment($loan, '3000');
    $p2 = $this->loans->registerPayment($loan->refresh(), '2000');

    expect($p1->balance_after)->toBe('9000.00')
        ->and($p2->balance_after)->toBe('7000.00');
});

it('no presta a un cliente de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena SRL'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Customer::create(['name' => 'Cliente Ajeno']);
    app(CurrentCompany::class)->set($this->company->id);

    expect(fn () => $this->loans->create(new CreateLoanData(
        customerId: $ajeno->id, principal: '1000', installmentsCount: 1,
        frequency: LoanFrequency::Monthly, startDate: '2026-08-01',
    )))->toThrow(LoanException::class);
});
