<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Loans\DTOs\CreateApplicationData;
use App\Modules\Loans\Enums\LoanApplicationStatus;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Exceptions\LoanException;
use App\Modules\Loans\Models\Loan;
use App\Modules\Loans\Models\LoanApplication;
use App\Modules\Loans\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
 * Solicitudes de préstamo: el embudo que va ANTES de que salga el dinero.
 *
 * Lo que se fija aquí no es el CRUD, es el dinero. Una solicitud es papel; el desembolso es efectivo
 * saliendo de la caja, y los dos peores fallos posibles son que el papel mueva dinero sin querer o
 * que el mismo papel lo mueva dos veces.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Financiera Uno'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@financiera.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->customer = Customer::create(['name' => 'Ana Cliente', 'cedula' => '001-1909443-4']);
});

/**
 * Solicitud de 10.000 al 20 % en 4 cuotas: total 12.000, cuota 3.000.
 */
function solicitud(int $customerId, array $extra = []): LoanApplication
{
    return app(LoanApplicationService::class)->create(CreateApplicationData::fromArray(array_merge([
        'customer_id' => $customerId,
        'principal' => '10000',
        'interest_rate' => '20',
        'installments_count' => 4,
        'frequency' => LoanFrequency::Monthly->value,
        'start_date' => '2026-09-01',
    ], $extra)));
}

/** Movimientos de dinero registrados por la empresa activa. */
function movimientos(): int
{
    return FinancialMovement::query()->count();
}

// ------------------------------------------------------------------------ La regla que gobierna

it('registrar una solicitud NO mueve dinero', function (): void {
    solicitud($this->customer->id);

    expect(movimientos())->toBe(0);
});

it('aprobar NO mueve dinero ni crea el préstamo', function (): void {
    // Es la regla que separa este módulo de lo que había: antes, aprobar y entregar el efectivo eran
    // el mismo acto. Si esto se rompe, el dinero sale en el momento de decidir, que es justo lo que
    // se vino a evitar.
    $sol = app(LoanApplicationService::class)->approve(solicitud($this->customer->id));

    expect($sol->status)->toBe(LoanApplicationStatus::Approved)
        ->and($sol->loan_id)->toBeNull()
        ->and(movimientos())->toBe(0);
});

it('desembolsar crea el préstamo y saca el capital de la caja', function (): void {
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));

    $prestamo = $servicio->disburse($sol);

    expect($prestamo->principal)->toBe('10000.00')
        ->and($prestamo->total)->toBe('12000.00')
        ->and($prestamo->installments()->count())->toBe(4)
        ->and($sol->fresh()->status)->toBe(LoanApplicationStatus::Disbursed)
        ->and($sol->fresh()->loan_id)->toBe($prestamo->id)
        ->and(movimientos())->toBe(1);
});

// --------------------------------------------------------------- Se desembolsa lo APROBADO

it('desembolsa los términos aprobados, no los solicitados', function (): void {
    // El fallo que costaría dinero de verdad: se aprueban 30.000 de los 50.000 pedidos y salen
    // 50.000 porque alguien leyó la columna equivocada.
    $servicio = app(LoanApplicationService::class);
    $sol = solicitud($this->customer->id, ['principal' => '50000']);

    $sol = $servicio->approve($sol, ['principal' => '30000', 'installments_count' => 6]);
    $prestamo = $servicio->disburse($sol);

    expect($prestamo->principal)->toBe('30000.00')
        ->and($prestamo->installments_count)->toBe(6)
        ->and($prestamo->total)->toBe('36000.00')   // 30.000 + 20 %
        ->and($sol->fresh()->principal)->toBe('50000.00'); // lo pedido NO se pisa
});

it('sin ajustes, los términos aprobados quedan nulos y vale lo solicitado', function (): void {
    // Copiar lo pedido en las columnas de aprobado haría creer, al leer el expediente, que hubo una
    // negociación que nunca existió.
    $sol = app(LoanApplicationService::class)->approve(solicitud($this->customer->id));

    expect($sol->approved_principal)->toBeNull()
        ->and($sol->seAjustaronLosTerminos())->toBeFalse()
        ->and($sol->capitalEfectivo())->toBe('10000.00');
});

// ---------------------------------------------------------------------- Transiciones imposibles

it('no se desembolsa dos veces la misma solicitud', function (): void {
    // Dos préstamos y dos egresos por una sola operación. Es el peor fallo posible de esta pantalla.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));
    $servicio->disburse($sol);

    expect(fn () => $servicio->disburse($sol->fresh()))->toThrow(LoanException::class);
    expect(movimientos())->toBe(1);
});

it('no se desembolsa una solicitud que no está aprobada', function (): void {
    $servicio = app(LoanApplicationService::class);

    expect(fn () => $servicio->disburse(solicitud($this->customer->id)))->toThrow(LoanException::class);

    $rechazada = $servicio->reject(solicitud($this->customer->id));
    expect(fn () => $servicio->disburse($rechazada))->toThrow(LoanException::class);
    expect(movimientos())->toBe(0);
});

it('no se decide dos veces', function (): void {
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));

    expect(fn () => $servicio->approve($sol->fresh()))->toThrow(LoanException::class);
    expect(fn () => $servicio->reject($sol->fresh()))->toThrow(LoanException::class);
});

it('una solicitud decidida ya no se puede editar', function (): void {
    // Poder cambiar el capital pedido después de aprobar dejaría el expediente sin decir qué se
    // aprobó en realidad.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));

    expect(fn () => $servicio->evaluate($sol->fresh(), ['monthly_income' => '50000']))
        ->toThrow(LoanException::class);
});

it('una desembolsada no se reabre', function (): void {
    // Deshacerla exigiría reversar el egreso y el préstamo; para eso está anular el préstamo.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));
    $servicio->disburse($sol);

    expect(fn () => $servicio->reopen($sol->fresh()))->toThrow(LoanException::class);
});

it('reabrir borra la decisión y los términos aprobados', function (): void {
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id), ['principal' => '7000']);

    $sol = $servicio->reopen($sol);

    expect($sol->status)->toBe(LoanApplicationStatus::UnderReview)
        ->and($sol->approved_principal)->toBeNull()
        ->and($sol->decided_at)->toBeNull()
        ->and($sol->capitalEfectivo())->toBe('10000.00');
});

// -------------------------------------------------------------------------------- Evaluación

it('guardar la evaluación pasa la solicitud a «en evaluación»', function (): void {
    $sol = app(LoanApplicationService::class)->evaluate(solicitud($this->customer->id), [
        'monthly_income' => '40000',
        'guarantor_name' => 'Pedro Garante',
    ]);

    expect($sol->status)->toBe(LoanApplicationStatus::UnderReview)
        ->and($sol->guarantor_name)->toBe('Pedro Garante');
});

it('una segunda tanda de datos no borra la primera', function (): void {
    // La evaluación se llena a trozos: los ingresos hoy, la cédula del garante cuando el cliente la
    // traiga. Con el atajo obvio —leer cada campo con `?? null`— la segunda llamada dejaría los
    // ingresos en blanco sin decir nada, y nadie se daría cuenta hasta ver la capacidad vacía.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->evaluate(solicitud($this->customer->id), ['monthly_income' => '40000']);

    $sol = $servicio->evaluate($sol, ['guarantor_cedula' => '402-1234567-8']);

    expect($sol->monthly_income)->toBe('40000.00')
        ->and($sol->guarantor_cedula)->toBe('402-1234567-8');
});

it('un campo enviado vacío sí se borra', function (): void {
    // Es como se corrige un ingreso mal tecleado: hay que poder dejarlo en blanco.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->evaluate(solicitud($this->customer->id), ['monthly_income' => '40000']);

    $sol = $servicio->evaluate($sol, ['monthly_income' => '']);

    expect($sol->monthly_income)->toBeNull()
        ->and($sol->capacidadDePago())->toBeNull();
});

it('la capacidad de pago descuenta gastos y otras deudas', function (): void {
    $sol = app(LoanApplicationService::class)->evaluate(solicitud($this->customer->id), [
        'monthly_income' => '40000',
        'monthly_expenses' => '15000',
        'other_debts' => '5000',
    ]);

    // 40.000 − 15.000 − 5.000 = 20.000 libres. La cuota es 3.000 → 15 %.
    expect($sol->capacidadDePago())->toBe('20000.00')
        ->and($sol->cuotaEstimada())->toBe('3000.00')
        ->and($sol->pesoDeLaCuota())->toBe('15.00');
});

it('sin ingreso declarado la capacidad es desconocida, no cero', function (): void {
    // Enseñar «0.00» haría creer que se evaluó y no le da, cuando lo que pasa es que nadie preguntó.
    $sol = solicitud($this->customer->id);

    expect($sol->capacidadDePago())->toBeNull()
        ->and($sol->pesoDeLaCuota())->toBeNull()
        ->and($sol->noLeSobraNada())->toBeFalse();
});

it('no divide entre cero cuando no le sobra nada', function (): void {
    $sol = app(LoanApplicationService::class)->evaluate(solicitud($this->customer->id), [
        'monthly_income' => '15000',
        'monthly_expenses' => '15000',
    ]);

    expect($sol->capacidadDePago())->toBe('0.00')
        ->and($sol->pesoDeLaCuota())->toBeNull()
        ->and($sol->noLeSobraNada())->toBeTrue();
});

it('el peso de la cuota usa los términos aprobados', function (): void {
    // Aprobar menos hace que la cuota quepa: ese es justo el uso de ajustar el capital.
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->evaluate(solicitud($this->customer->id, ['principal' => '100000']), [
        'monthly_income' => '20000',
    ]);

    expect($sol->pesoDeLaCuota())->toBe('150.00'); // cuota 30.000 sobre 20.000 libres

    $sol = $servicio->approve($sol, ['principal' => '20000']);

    expect($sol->pesoDeLaCuota())->toBe('30.00');  // cuota 6.000 sobre 20.000
});

// ------------------------------------------------------------------------------- Por HTTP

it('el dueño recorre solicitud → evaluación → aprobación → desembolso', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->post(route('panel.loan-applications.store'), [
            'customer_id' => $this->customer->id,
            'principal' => '10000',
            'interest_rate' => '20',
            'installments_count' => 4,
            'frequency' => LoanFrequency::Monthly->value,
            'start_date' => '2026-09-01',
        ])->assertRedirect();

    $sol = LoanApplication::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->put(route('panel.loan-applications.evaluate', $sol), ['monthly_income' => '40000'])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->post(route('panel.loan-applications.approve', $sol), ['principal' => '8000'])
        ->assertRedirect();

    expect(movimientos())->toBe(0);

    $this->actingAs($this->owner)
        ->post(route('panel.loan-applications.disburse', $sol))
        ->assertRedirect();

    expect($sol->fresh()->status)->toBe(LoanApplicationStatus::Disbursed)
        ->and($sol->fresh()->loan->principal)->toBe('8000.00')
        ->and(movimientos())->toBe(1);
});

it('el préstamo enlaza de vuelta a su solicitud', function (): void {
    // El expediente tiene que poder leerse en los dos sentidos: desde el préstamo, saber con qué
    // ingresos y qué garante se concedió; desde la solicitud, ver en qué acabó.
    $this->withoutVite();
    $servicio = app(LoanApplicationService::class);
    $sol = $servicio->approve(solicitud($this->customer->id));
    $prestamo = $servicio->disburse($sol);

    expect($prestamo->fresh()->application?->id)->toBe($sol->id);

    $this->actingAs($this->owner)
        ->get(route('panel.loans.show', $prestamo))
        ->assertOk()
        ->assertSee($sol->code);
});

it('un préstamo creado a mano no tiene solicitud y su ficha sigue abriendo', function (): void {
    // La pantalla de Préstamos no se retira: prestarle 2.000 al vecino no debería obligar a abrir
    // un expediente. La ficha tiene que aguantar que `application` sea nulo.
    $this->withoutVite();

    $this->actingAs($this->owner)->post(route('panel.loans.store'), [
        'customer_id' => $this->customer->id,
        'principal' => '2000',
        'installments_count' => 1,
        'frequency' => LoanFrequency::Monthly->value,
        'start_date' => '2026-09-01',
    ])->assertRedirect();

    $prestamo = Loan::query()->firstOrFail();

    expect($prestamo->application)->toBeNull();
    $this->actingAs($this->owner)->get(route('panel.loans.show', $prestamo))->assertOk();
});

it('la ficha y el listado responden', function (): void {
    $this->withoutVite();
    $sol = solicitud($this->customer->id);

    $this->actingAs($this->owner)->get(route('panel.loan-applications'))->assertOk()->assertSee($sol->code);
    $this->actingAs($this->owner)->get(route('panel.loan-applications.show', $sol))->assertOk();
});

// --------------------------------------------------------------------------------- Permisos

it('quien evalúa no necesariamente puede decidir', function (): void {
    // Es la única razón por la que la evaluación sirve de algo: quien atiende el mostrador no puede
    // concederse un préstamo a sí mismo.
    $this->withoutVite();

    $evaluador = User::create([
        'company_id' => $this->company->id, 'name' => 'Evaluador',
        'email' => 'evaluador@financiera.test', 'password' => 'secret-password',
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->company->id);
    $evaluador->givePermissionTo(['loan_applications.view', 'loan_applications.manage']);
    $registrar->forgetCachedPermissions();

    $sol = solicitud($this->customer->id);

    // Puede ver y evaluar...
    $this->actingAs($evaluador)->get(route('panel.loan-applications.show', $sol))->assertOk();
    $this->actingAs($evaluador)
        ->put(route('panel.loan-applications.evaluate', $sol), ['monthly_income' => '40000'])
        ->assertRedirect();

    // ...pero no aprobar ni desembolsar.
    $this->actingAs($evaluador)->post(route('panel.loan-applications.approve', $sol))->assertForbidden();
    $this->actingAs($evaluador)->post(route('panel.loan-applications.disburse', $sol))->assertForbidden();
});

it('sin el módulo de préstamos la pantalla queda cerrada', function (): void {
    $this->company->update(['modules' => ['pos', 'sales']]);

    $this->actingAs($this->owner)->get(route('panel.loan-applications'))->assertForbidden();
});

// ------------------------------------------------------------------------------ Aislamiento

it('no se ve la solicitud de otra empresa', function (): void {
    $this->withoutVite();
    $ajena = solicitud($this->customer->id);

    app(CurrentCompany::class)->forget();
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Financiera Dos'));
    app(CurrentCompany::class)->set($otra->id);
    $intruso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Otro', 'email' => 'otro@dos.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($intruso)->get(route('panel.loan-applications.show', $ajena))->assertNotFound();
    $this->actingAs($intruso)->get(route('panel.loan-applications'))->assertOk()->assertDontSee($ajena->code);
});

it('los códigos se numeran por empresa', function (): void {
    expect(solicitud($this->customer->id)->code)->toBe('SOL-000001')
        ->and(solicitud($this->customer->id)->code)->toBe('SOL-000002');
});
