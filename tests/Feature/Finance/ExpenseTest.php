<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\DTOs\CreateExpenseData;
use App\Modules\Finance\Enums\AccountType;
use App\Modules\Finance\Enums\MovementType;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Finance\Services\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
 * Gastos: la primera forma de que salga dinero del negocio por decisión de alguien.
 *
 * Hasta ahora el saldo de una cuenta solo subía (ventas) o bajaba al prestar. Era ficticio: pagar la
 * luz no tenía dónde escribirse. Lo que se fija aquí es que el saldo diga la verdad —después de
 * anotar un gasto, después de anularlo— y que el arqueo del turno no se quede fuera cuando el
 * dinero sale del cajón.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Gastos Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@gastos.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->cuenta = Account::query()->where('is_default', true)->firstOrFail();
    $this->concepto = ExpenseCategory::query()->where('name', 'Luz')->firstOrFail();
});

/** @param array<string, mixed> $extra */
function gasto(array $extra = []): Expense
{
    return app(ExpenseService::class)->create(CreateExpenseData::fromArray(array_merge([
        'account_id' => test()->cuenta->id,
        'expense_category_id' => test()->concepto->id,
        'amount' => '1500',
        'description' => 'Factura de luz',
        'paid_at' => now()->toDateString(),
    ], $extra)));
}

function saldo(): string
{
    return (string) Account::query()->whereKey(test()->cuenta->id)->value('balance');
}

/** Abre un turno de caja con un fondo inicial. */
function abrirTurno(string $fondo = '5000'): CashSession
{
    $caja = CashRegister::query()->first() ?? CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);

    return app(CashService::class)->open($caja, $fondo, test()->owner->id);
}

// ------------------------------------------------------------------ La cuenta dice la verdad

it('anotar un gasto baja el saldo de la cuenta', function (): void {
    expect(saldo())->toBe('0.00');

    gasto(['amount' => '1500']);

    expect(saldo())->toBe('-1500.00');
});

it('el apunte queda enlazado al gasto y es un egreso', function (): void {
    $g = gasto(['amount' => '750']);

    $apunte = $g->movement()->first();

    expect($apunte)->not->toBeNull()
        ->and($apunte->type)->toBe(MovementType::Expense)
        // El importe se guarda CON SIGNO, como el resto del libro.
        ->and($apunte->amount)->toBe('-750.00')
        ->and($apunte->description)->toContain('Luz');
});

it('anular devuelve el dinero exacto al saldo', function (): void {
    // Es el fallo que más caro sale: un saldo que se va desviando con cada anulación y que nadie
    // detecta hasta que no cuadra con el banco.
    $g = gasto(['amount' => '1234.56']);
    expect(saldo())->toBe('-1234.56');

    app(ExpenseService::class)->void($g);

    expect(saldo())->toBe('0.00')
        ->and(FinancialMovement::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0);
});

it('anotar y anular muchas veces deja el saldo donde estaba', function (): void {
    // Cada vuelta suma y resta lo mismo; si hubiera un error de signo o de redondeo, se acumularía.
    foreach (['10.01', '99.99', '0.05', '333.33'] as $monto) {
        app(ExpenseService::class)->void(gasto(['amount' => $monto]));
    }

    expect(saldo())->toBe('0.00');
});

it('rechaza un monto de cero o negativo', function (): void {
    expect(fn () => gasto(['amount' => '0']))->toThrow(FinanceException::class);
    expect(fn () => gasto(['amount' => '-50']))->toThrow(FinanceException::class);
    expect(saldo())->toBe('0.00');
});

// ------------------------------------------------------------------------- El cajón del turno

it('un gasto en efectivo con la caja abierta también sale del arqueo', function (): void {
    // Sin esto, el dinero que se paga del cajón aparece como FALTANTE al cerrar el turno, y el
    // cajero acaba cuadrando a mano. El arqueo deja de servir para detectar un faltante de verdad.
    $turno = abrirTurno('5000');

    $g = gasto(['amount' => '800']);

    $apunteCaja = $g->cashMovement()->first();

    expect($apunteCaja)->not->toBeNull()
        ->and($apunteCaja->type)->toBe(CashMovementType::Expense)
        ->and($apunteCaja->amount)->toBe('-800.00')
        ->and($apunteCaja->cash_session_id)->toBe($turno->id);
});

it('un gasto pagado del banco NO toca el arqueo', function (): void {
    abrirTurno();

    $banco = Account::create([
        'name' => 'Banco Popular', 'type' => AccountType::Bank, 'balance' => '0', 'is_active' => true,
    ]);

    $g = gasto(['account_id' => $banco->id, 'amount' => '2000']);

    expect($g->cashMovement()->exists())->toBeFalse()
        ->and((string) $banco->fresh()->balance)->toBe('-2000.00');
});

it('sin turno abierto solo se anota en la cuenta', function (): void {
    $g = gasto(['amount' => '400']);

    expect($g->cashMovement()->exists())->toBeFalse()
        ->and(saldo())->toBe('-400.00');
});

it('anular devuelve el dinero al arqueo del turno abierto', function (): void {
    abrirTurno('5000');
    $g = gasto(['amount' => '800']);

    app(ExpenseService::class)->void($g);

    expect(saldo())->toBe('0.00')
        ->and($g->cashMovement()->withoutGlobalScopes()->exists())->toBeFalse();
});

it('no se anula un gasto de un turno ya cerrado', function (): void {
    // Aquel arqueo se contó y se firmó con ese dinero fuera. Quitarlo ahora dejaría el cierre
    // diciendo una cifra que nadie contó.
    $turno = abrirTurno('5000');
    $g = gasto(['amount' => '800']);

    app(CashService::class)->close($turno, '4200');

    expect(fn () => app(ExpenseService::class)->void($g))->toThrow(FinanceException::class);
    expect(saldo())->toBe('-800.00'); // no se tocó nada
});

// -------------------------------------------------------------------------------- Conceptos

it('una empresa nueva nace con conceptos de gasto', function (): void {
    // Si hubiera que crear «Luz» antes de poder anotar la factura de la luz, todo acabaría en el
    // primer concepto que hubiese y el informe nacería inservible.
    expect(ExpenseCategory::query()->count())->toBe(count(ExpenseCategory::INICIALES))
        ->and(ExpenseCategory::query()->pluck('name'))->toContain('Alquiler', 'Luz', 'Nómina');
});

it('el concepto es obligatorio y tiene que ser de la empresa', function (): void {
    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    $ajeno = ExpenseCategory::withoutGlobalScopes()->where('company_id', $otra->id)->firstOrFail();

    app(CurrentCompany::class)->set($this->company->id);

    expect(fn () => gasto(['expense_category_id' => $ajeno->id]))->toThrow(FinanceException::class);
});

it('desactivar un concepto no toca los gastos que ya lo usan', function (): void {
    // El informe del año pasado tiene que seguir diciendo lo mismo.
    $g = gasto();
    $this->concepto->update(['is_active' => false]);

    expect($g->fresh()->category->name)->toBe('Luz')
        ->and(ExpenseCategory::query()->usables()->pluck('name'))->not->toContain('Luz');
});

// ---------------------------------------------------------------------------------- Por HTTP

it('el dueño anota un gasto desde la pantalla', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)->post(route('panel.expenses.store'), [
        'account_id' => $this->cuenta->id,
        'expense_category_id' => $this->concepto->id,
        'amount' => '2500',
        'description' => 'Factura de luz de agosto',
        'paid_at' => now()->toDateString(),
    ])->assertRedirect();

    expect(saldo())->toBe('-2500.00')
        ->and(Expense::query()->first()->code)->toBe('GAS-000001');
});

it('rechaza un gasto con fecha futura', function (): void {
    // Dinero que todavía no ha salido no es un gasto: es una cuenta por pagar.
    $this->withoutVite();

    $this->actingAs($this->owner)->post(route('panel.expenses.store'), [
        'account_id' => $this->cuenta->id,
        'expense_category_id' => $this->concepto->id,
        'amount' => '100',
        'description' => 'Adelantado',
        'paid_at' => now()->addWeek()->toDateString(),
    ])->assertSessionHasErrors('paid_at');

    expect(saldo())->toBe('0.00');
});

it('la pantalla agrupa por concepto y suma el período', function (): void {
    $this->withoutVite();
    $alquiler = ExpenseCategory::query()->where('name', 'Alquiler')->firstOrFail();

    gasto(['amount' => '1000']);
    gasto(['amount' => '500']);
    gasto(['amount' => '8000', 'expense_category_id' => $alquiler->id, 'description' => 'Local']);

    $html = $this->actingAs($this->owner)->get(route('panel.expenses'))->assertOk()->getContent();

    // Total del período y las dos agrupaciones.
    expect($html)->toContain('9,500.00')   // 1000 + 500 + 8000
        ->and($html)->toContain('8,000.00') // Alquiler
        ->and($html)->toContain('1,500.00'); // Luz
});

it('el listado respeta el rango de fechas', function (): void {
    $this->withoutVite();
    gasto(['amount' => '100', 'paid_at' => now()->subMonths(3)->toDateString(), 'description' => 'Gasto viejo']);
    gasto(['amount' => '200', 'description' => 'Gasto de este mes']);

    // Por defecto, el mes en curso.
    $html = $this->actingAs($this->owner)->get(route('panel.expenses'))->getContent();
    expect($html)->toContain('Gasto de este mes')->and($html)->not->toContain('Gasto viejo');

    // Ampliando el rango aparecen los dos.
    $html = $this->actingAs($this->owner)
        ->get(route('panel.expenses', ['desde' => now()->subYear()->toDateString(), 'hasta' => now()->toDateString()]))
        ->getContent();
    expect($html)->toContain('Gasto viejo');
});

// ------------------------------------------------------------------------ Permisos y aislamiento

it('sin finance.manage se puede mirar pero no anotar', function (): void {
    $this->withoutVite();

    $mirón = User::create([
        'company_id' => $this->company->id, 'name' => 'Contable',
        'email' => 'contable@gastos.test', 'password' => 'secret-password',
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    $mirón->givePermissionTo(['finance.view', 'dashboard.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($mirón)->get(route('panel.expenses'))->assertOk();
    $this->actingAs($mirón)->post(route('panel.expenses.store'), [])->assertForbidden();
});

it('sin el módulo de finanzas la pantalla queda cerrada', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);

    $this->actingAs($this->owner)->get(route('panel.expenses'))->assertForbidden();
});

it('no se ve el gasto de otra empresa', function (): void {
    $this->withoutVite();
    $ajeno = gasto(['description' => 'Gasto de la otra']);

    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Intrusa Co'));
    app(CurrentCompany::class)->set($otra->id);
    $intruso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Otro', 'email' => 'otro@intrusa.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($intruso)->get(route('panel.expenses'))->assertOk()->assertDontSee('Gasto de la otra');
    $this->actingAs($intruso)->delete(route('panel.expenses.destroy', $ajeno))->assertNotFound();
});

it('los códigos se numeran por empresa', function (): void {
    expect(gasto()->code)->toBe('GAS-000001')
        ->and(gasto()->code)->toBe('GAS-000002');
});
