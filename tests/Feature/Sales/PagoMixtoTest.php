<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\DgiiReportService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\PaymentData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\PaymentSplitException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * EL COBRO REPARTIDO ENTRE VARIAS FORMAS DE PAGO.
 *
 * Esto toca dinero por tres sitios a la vez —el cajón del turno, la cuenta de la empresa y el 607 de
 * la DGII— y los tres se descuadran de formas distintas si el reparto falla. De ahí que casi todos
 * estos tests miren importes concretos y no «que funcione».
 *
 * La invariante que lo sostiene todo: la suma de lo imputado es EXACTAMENTE el total de la venta, y
 * el vuelto nunca está dentro.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Mixta Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@mixta.test', 'password' => 'secret-password',
    ]), 'owner');
    $this->actingAs($this->owner);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    // Un artículo de 1.000 exactos: los repartos se leen de un vistazo.
    $this->pieza = Product::create(['sku' => 'MIX-1', 'name' => 'Pieza', 'cost' => '400', 'price' => '1000']);
    app(StockService::class)->increase($this->pieza, $this->warehouse, StockMovementType::Purchase, '50');

    $caja = CashRegister::create([
        'company_id' => $this->company->id, 'name' => 'Caja 1', 'code' => 'CAJA-01', 'is_active' => true,
    ]);
    $this->turno = CashSession::create([
        'company_id' => $this->company->id,
        'cash_register_id' => $caja->id,
        'user_id' => $this->owner->id,
        'status' => 'open',
        'opening_amount' => '1000',
        'opened_at' => now(),
    ]);
});

/** Una venta de `$cuantas` piezas, cobrada con el reparto indicado. */
function cobroRepartido(array $entregas, string $cuantas = '1'): Sale
{
    return app(CheckoutService::class)->checkout(
        test()->turno,
        new CreateSaleData(
            warehouseId: test()->warehouse->id,
            lines: [new SaleLineData(test()->pieza->id, $cuantas, '1000')],
            payments: $entregas,
        ),
    );
}

/** Lo que hay hoy en el cajón del turno, sin contar el fondo. */
function enElCajon(): string
{
    return (string) (DB::table('cash_movements')->where('cash_session_id', test()->turno->id)->sum('amount') ?? '0');
}

/** Las columnas 17 a 23 (formas de pago) de la única línea de datos del 607. */
function columnasDePago607(): array
{
    $lineas = preg_split('/\R/', trim(app(DgiiReportService::class)->sales607(now())));

    // La PRIMERA línea del fichero es la cabecera del envío —formato, RNC, periodo y nº de
    // registros—; los comprobantes empiezan en la segunda.
    return array_slice(explode('|', $lineas[1]), 16, 7);
}

// ------------------------------------------------------------------ Lo básico del reparto

it('reparte el cobro entre efectivo y tarjeta y la venta suma el total', function (): void {
    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    expect($venta->total)->toBe('1000.00')
        ->and($venta->payments)->toHaveCount(2)
        ->and($venta->desglose()->total())->toBe('1000.00')
        ->and($venta->desglose()->de(PaymentMethod::Card))->toBe('400.00')
        ->and($venta->desglose()->de(PaymentMethod::Cash))->toBe('600.00');
});

/*
 * EL TEST QUE MÁS IMPORTA DE TODA LA ETAPA.
 *
 * Al cajón solo entra el efectivo. Si entrara el total, el turno cerraría con un sobrante exactamente
 * igual a lo cobrado con tarjeta, y el cajero acabaría cuadrando a mano una diferencia que no existe.
 */
it('solo la parte en efectivo entra al cajon', function (): void {
    cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    expect((float) enElCajon())->toBe(600.0)
        // UN solo movimiento, no uno por vía: es lo que permite que anular lo deshaga sin tocar el
        // código de anulación, que borra por referencia.
        ->and(DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->count())->toBe(1);
});

it('el arqueo cuadra con un cobro repartido', function (): void {
    cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    // Fondo 1000 + 600 en billetes = 1600 esperados. El cajero cuenta 1600 y no hay diferencia.
    $cerrada = app(CashService::class)->close($this->turno->fresh(), '1600');

    expect($cerrada->expected_amount)->toBe('1600.00')
        ->and($cerrada->difference)->toBe('0.00');
});

/*
 * El vuelto sale SOLO de lo entregado en efectivo. Es la única vía que puede dar de más: el cajón
 * tiene billetes para devolver y un datáfono no.
 */
it('el cambio se calcula solo sobre lo entregado en efectivo', function (): void {
    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '700'),   // entrega 700 para cubrir 600
    ]);

    expect($venta->change)->toBe('100.00')
        ->and($venta->paid)->toBe('1100.00')
        // Al cajón entran 600, no 700: los 100 salieron como vuelto.
        ->and((float) enElCajon())->toBe(600.0);
});

it('rechaza un cobro repartido que no llega al total', function (): void {
    expect(fn () => cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Transfer, '300'),
    ]))->toThrow(PaymentSplitException::class);

    expect(Sale::count())->toBe(0);
});

/*
 * Un datáfono no da vuelto. Si la tarjeta cobró de más, ese dinero está en la cuenta del negocio y
 * hay que devolverlo por donde entró: repartir el sobrante aquí lo taparía.
 */
it('rechaza el vuelto sobre una tarjeta', function (): void {
    expect(fn () => cobroRepartido([new PaymentData(PaymentMethod::Card, '1500')]))
        ->toThrow(PaymentSplitException::class);
});

it('rechaza una forma de pago con importe cero', function (): void {
    expect(fn () => cobroRepartido([
        new PaymentData(PaymentMethod::Card, '0'),
        new PaymentData(PaymentMethod::Cash, '1000'),
    ]))->toThrow(PaymentSplitException::class);
});

// ------------------------------------------------------------------ La cabecera de la venta

/*
 * NO se inventa un valor «mixto» para `payment_method`: se guarda la vía de mayor importe. Todos los
 * `match` que ya existen sobre esa columna tienen rama por omisión —«Otras» en el 607—, así que un
 * valor nuevo no fallaría: declararía mal cada venta repartida, en silencio.
 */
it('la venta guarda como forma de pago la de mayor importe, y siempre una valida', function (): void {
    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    expect($venta->payment_method)->toBe('cash')
        ->and(PaymentMethod::tryFrom($venta->payment_method))->not->toBeNull();
});

/*
 * También se escribe la fila cuando hay UNA sola forma de pago. Es lo que permite que todo el lado
 * lectura tenga un camino y no dos, y deja el respaldo por cabecera solo para las ventas antiguas.
 */
it('una venta de una sola forma de pago tambien deja su fila', function (): void {
    $venta = app(CheckoutService::class)->checkout(
        $this->turno,
        new CreateSaleData(
            warehouseId: $this->warehouse->id,
            lines: [new SaleLineData($this->pieza->id, '1', '1000')],
            paymentMethod: PaymentMethod::Cash,
            paid: '1000',
        ),
    );

    expect($venta->payments)->toHaveCount(1)
        ->and($venta->payments->first()->method)->toBe(PaymentMethod::Cash)
        ->and($venta->payments->first()->amount)->toBe('1000.00');
});

// ------------------------------------------------------------------ Anular una venta repartida

it('anular una venta repartida saca del cajon solo lo que entro en efectivo', function (): void {
    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    app(SaleVoidService::class)->void($venta->fresh());

    // El cajón vuelve a como estaba: el movimiento se borró entero.
    expect((float) enElCajon())->toBe(0.0);

    $cerrada = app(CashService::class)->close($this->turno->fresh(), '1000');

    expect($cerrada->expected_amount)->toBe('1000.00')
        ->and($cerrada->difference)->toBe('0.00');
});

/*
 * Las filas de pago SOBREVIVEN a la anulación, y así debe ser: la venta es un borrado lógico, y su
 * desglose es la explicación de por qué salieron esos pesos del cajón.
 */
it('las filas de pago sobreviven a la anulacion', function (): void {
    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);

    app(SaleVoidService::class)->void($venta->fresh());

    expect(DB::table('sale_payments')->where('sale_id', $venta->id)->count())->toBe(2);
});

// ------------------------------------------------------------------ El 607 de la DGII

/*
 * La DGII valida que las columnas 17 a 23 sumen EXACTAMENTE el total facturado; un céntimo de
 * diferencia tumba el envío del mes entero. Y con un cobro repartido, declarar el total en una sola
 * columna sería declarar mal.
 */
it('el 607 reparte el importe entre las columnas de forma de pago', function (): void {
    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1, 'range_from' => 1,
        'range_to' => 1000, 'number_length' => 8, 'is_active' => true,
    ]);

    $venta = cobroRepartido([
        new PaymentData(PaymentMethod::Card, '400'),
        new PaymentData(PaymentMethod::Cash, '600'),
    ]);
    app(InvoiceService::class)->issueForSale($venta->fresh(), NcfType::Consumo);

    $formas = columnasDePago607();

    expect($formas[0])->toBe('600.00')   // efectivo
        ->and($formas[2])->toBe('400.00') // tarjeta
        ->and($formas[1])->toBe('0.00')   // cheque/transferencia
        ->and($formas[3])->toBe('0.00');  // crédito

    // Y lo que la DGII comprueba: las siete suman el total facturado.
    $suma = array_reduce($formas, fn (string $s, string $v): string => bcadd($s, $v, 2), '0');
    expect($suma)->toBe('1000.00');
});

/*
 * Una venta ANTERIOR al reparto no tiene filas, y tiene que seguir declarándose igual que siempre.
 * Es el respaldo por cabecera: sin él, todas las ventas históricas saldrían con las siete columnas a
 * cero y el envío del mes no cuadraría.
 */
it('una venta sin filas de pago sigue declarando el total en su columna', function (): void {
    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1, 'range_from' => 1,
        'range_to' => 1000, 'number_length' => 8, 'is_active' => true,
    ]);

    $venta = app(CheckoutService::class)->checkout(
        $this->turno,
        new CreateSaleData(
            warehouseId: $this->warehouse->id,
            lines: [new SaleLineData($this->pieza->id, '1', '1000')],
            paymentMethod: PaymentMethod::Card,
            paid: '1000',
        ),
    );

    // Se borran las filas para dejarla como una venta de antes de que existiera la tabla.
    DB::table('sale_payments')->where('sale_id', $venta->id)->delete();

    app(InvoiceService::class)->issueForSale($venta->fresh(), NcfType::Consumo);

    $formas = columnasDePago607();

    expect($formas[2])->toBe('1000.00')   // tarjeta, leída de la cabecera
        ->and($formas[0])->toBe('0.00');
});

// ------------------------------------------------------------------ Sin la tabla todavía

/*
 * En producción las migraciones se aplican a mano. Entre que sale este código y alguien migra, la
 * tabla no existe: cobrar con UNA forma tiene que seguir funcionando exactamente igual. Es la
 * degradación que de verdad importa, porque afecta a todas las ventas normales.
 */
it('sin la tabla de pagos el cobro normal sigue funcionando', function (): void {
    Schema::drop('sale_payments');
    DbTable::olvidar();

    $venta = app(CheckoutService::class)->checkout(
        $this->turno,
        new CreateSaleData(
            warehouseId: $this->warehouse->id,
            lines: [new SaleLineData($this->pieza->id, '1', '1000')],
            paymentMethod: PaymentMethod::Cash,
            paid: '1000',
        ),
    );

    expect($venta->total)->toBe('1000.00')
        // El desglose se sintetiza desde la cabecera y el cajón recibe lo mismo de siempre.
        ->and($venta->desglose()->efectivo())->toBe('1000.00')
        ->and((float) enElCajon())->toBe(1000.0);
});
