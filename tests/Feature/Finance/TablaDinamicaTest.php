<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Enums\ExpenseGroup;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Support\TablaDinamicaDeGastos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

/*
 * LA TABLA DINÁMICA DE GASTOS.
 *
 * Cruza «en qué se gasta» contra «cuándo». Es la única parte de la pantalla que hace aritmética, así
 * que los tests miran importes concretos y no «que devuelva algo».
 *
 * La invariante: la suma de las celdas de una fila es su total, la de una columna es el total de esa
 * columna, y las dos sumas llegan al mismo gran total. Si eso se rompe, el dueño ve unos números que
 * no cuadran con otros de la misma pantalla y deja de fiarse de los dos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cafetería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@gastos.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->cuenta = Account::query()->where('is_default', true)->firstOrFail();

    /*
     * Se CLASIFICAN conceptos en vez de crearlos a secas: al dar de alta una empresa ya se le
     * siembran los suyos —«Luz» es uno—, y crear uno repetido choca contra el índice único de
     * (empresa, nombre). Es además lo que va a pasar de verdad: los conceptos existen y lo que se
     * añade ahora es su categoría.
     */
    $this->cafe = concepto('Café en grano', ExpenseGroup::Alimentos);
    $this->leche = concepto('Leche', ExpenseGroup::Alimentos);
    $this->luz = concepto('Luz', ExpenseGroup::Servicios);
});

function concepto(string $nombre, ?ExpenseGroup $categoria = null): ExpenseCategory
{
    return ExpenseCategory::updateOrCreate(
        ['company_id' => test()->company->id, 'name' => $nombre],
        ['category' => $categoria, 'is_active' => true],
    );
}

function apunte(ExpenseCategory $concepto, string $monto, string $fecha): Expense
{
    return Expense::create([
        'company_id' => test()->company->id,
        'code' => 'G-'.str()->random(6),
        'account_id' => test()->cuenta->id,
        'expense_category_id' => $concepto->id,
        'amount' => $monto,
        'description' => 'Apunte de prueba',
        'paid_at' => $fecha,
    ]);
}

it('cruza el gasto por categoria y por dia, y las dos sumas cuadran', function (): void {
    apunte($this->cafe, '1000', '2026-09-01');
    apunte($this->leche, '500', '2026-09-01');   // misma categoría, otro concepto
    apunte($this->luz, '800', '2026-09-03');

    $t = TablaDinamicaDeGastos::de(
        Expense::with(['category', 'account', 'supplier'])->get(),
        'categoria', 'dia',
        Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-03'),
    );

    expect($t->columnas)->toHaveCount(3)                     // 1, 2 y 3 de septiembre
        ->and($t->filas)->toHaveCount(2);                    // Alimentos y Servicios

    // Alimentos va primero: se ordena por lo que más pesa, que es lo primero que hay que mirar.
    expect($t->filas[0]['rotulo'])->toBe('Alimentos')
        // Los dos conceptos del día 1 se suman en la misma celda.
        ->and($t->celda($t->filas[0], '2026-09-01'))->toBe('1500.00')
        ->and($t->filas[0]['total'])->toBe('1500.00')
        ->and($t->filas[1]['rotulo'])->toBe('Servicios')
        ->and($t->filas[1]['total'])->toBe('800.00');

    // Y las dos sumas llegan al mismo sitio.
    expect($t->totales['2026-09-01'])->toBe('1500.00')
        ->and($t->totales['2026-09-03'])->toBe('800.00')
        ->and($t->granTotal)->toBe('2300.00');
});

/*
 * EL HUECO ES EL DATO.
 *
 * Un día sin gastos tiene que salir como columna vacía, no desaparecer. Si solo se pintaran los días
 * con movimiento, tres días sin gastar se verían igual que tres días seguidos gastando — y ver el
 * hueco es justo para lo que se mira esta tabla.
 */
it('los dias sin gasto salen igual, vacios', function (): void {
    apunte($this->cafe, '1000', '2026-09-01');
    apunte($this->cafe, '400', '2026-09-05');

    $t = TablaDinamicaDeGastos::de(
        Expense::with(['category', 'account', 'supplier'])->get(),
        'categoria', 'dia',
        Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-05'),
    );

    expect($t->columnas)->toHaveCount(5)
        // Los del medio existen como columna y no tienen celda: null, no cero.
        ->and($t->celda($t->filas[0], '2026-09-03'))->toBeNull()
        ->and($t->totales['2026-09-03'])->toBe('0.00')
        ->and($t->granTotal)->toBe('1400.00');
});

it('agrupa por concepto cuando se le pide ese eje', function (): void {
    apunte($this->cafe, '1000', '2026-09-01');
    apunte($this->leche, '500', '2026-09-01');

    $t = TablaDinamicaDeGastos::de(
        Expense::with(['category', 'account', 'supplier'])->get(),
        'concepto', 'dia',
        Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-01'),
    );

    // Ahora son dos filas donde por categoría había una.
    expect($t->filas)->toHaveCount(2)
        ->and($t->filas[0]['rotulo'])->toBe('Café en grano')
        ->and($t->filas[1]['rotulo'])->toBe('Leche');
});

/*
 * «SIN CLASIFICAR» NO ES «OTROS», y por eso va en su propia fila.
 *
 * Un concepto que nadie ha clasificado todavía y uno que se decidió que no encaja en ninguna
 * categoría son cosas distintas. Mezclarlos escondería que hay trabajo pendiente de hacer.
 */
it('lo que nadie ha clasificado se ve aparte', function (): void {
    $suelto = concepto('Algo');                        // sin categoría: nadie lo ha mirado
    $otros = concepto('Varios', ExpenseGroup::Otros);   // decidido: no encaja en ninguna

    apunte($suelto, '100', '2026-09-01');
    apunte($otros, '200', '2026-09-01');

    $t = TablaDinamicaDeGastos::de(
        Expense::with(['category', 'account', 'supplier'])->get(),
        'categoria', 'dia',
        Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-01'),
    );

    $rotulos = array_column($t->filas, 'rotulo');

    expect($rotulos)->toContain('Sin clasificar')
        ->and($rotulos)->toContain('Otros')
        ->and($t->filas)->toHaveCount(2);
});

/*
 * La granularidad automática. Un rango de un año con una columna por día son 365 columnas: no se lee,
 * se sufre. Y un rango de una semana por meses es una sola columna, que no cruza nada.
 */
it('elige sola la granularidad segun lo largo que sea el rango', function (): void {
    $vacio = new Collection;

    $semana = TablaDinamicaDeGastos::de($vacio, 'categoria', '', Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-07'));
    expect($semana->columnas)->toHaveCount(7);                       // por día

    $anio = TablaDinamicaDeGastos::de($vacio, 'categoria', '', Carbon\Carbon::parse('2026-01-01'), Carbon\Carbon::parse('2026-12-31'));
    expect($anio->columnas)->toHaveCount(12);                        // por mes
});

/*
 * EL GRÁFICO SALE DEL MISMO CÁLCULO QUE LA TABLA.
 *
 * Si consultara por su cuenta, bastaría un filtro distinto para que la barra dijera una cosa y la
 * celda de al lado otra — y quien mira deja de fiarse de las dos.
 *
 * Y LAS SERIES SE CORTAN EN SIETE. Con trece categorías, dos tonos vecinos son indistinguibles para
 * quien no distingue bien el color. Las seis primeras van sueltas y el resto se pliega en «Otros»,
 * que sigue sumando lo mismo: el total del gráfico tiene que seguir siendo el de la tabla.
 */
it('el grafico pliega la cola en Otros sin perder un peso', function (): void {
    // Ocho categorías distintas: una más de las que caben sueltas.
    $ocho = [
        ExpenseGroup::Alimentos, ExpenseGroup::Bebidas, ExpenseGroup::Insumos, ExpenseGroup::Limpieza,
        ExpenseGroup::Servicios, ExpenseGroup::Alquiler, ExpenseGroup::Transporte, ExpenseGroup::Publicidad,
    ];

    foreach ($ocho as $i => $categoria) {
        apunte(concepto('Concepto '.$i, $categoria), (string) (1000 - $i * 50), '2026-09-01');
    }

    $t = TablaDinamicaDeGastos::de(
        Expense::with(['category', 'account', 'supplier'])->get(),
        'categoria', 'dia',
        Carbon\Carbon::parse('2026-09-01'), Carbon\Carbon::parse('2026-09-01'),
    );

    $g = $t->paraGrafico();

    // Seis sueltas y «Otros»: nunca ocho colores.
    expect($g['series'])->toHaveCount(7)
        ->and($g['series'][6]['nombre'])->toBe('Otros')
        // El ranking NO se corta: es una lista con barras, no una paleta.
        ->and($g['ranking'])->toHaveCount(8);

    // Y lo que importa: plegar no pierde dinero.
    $suma = '0';

    foreach ($g['series'] as $serie) {
        foreach ($serie['datos'] as $valor) {
            $suma = bcadd($suma, number_format($valor, 2, '.', ''), 2);
        }
    }

    expect($suma)->toBe($t->granTotal);
});
