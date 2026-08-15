<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Models\Account;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Anulación de ventas.
 *
 * Copiar el borrado del inventario habría sido más fácil, pero una venta no es un producto: al
 * cobrarla se descontó stock y entró dinero en la caja. Quitarla del listado sin deshacer eso
 * dejaría las existencias y el arqueo mintiendo, y nadie se enteraría hasta contar el inventario o
 * cuadrar la caja.
 *
 * Por eso lo que se prueba aquí no es «desaparece de la lista», sino que las CIFRAS CUADRAN después.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Heladería'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@heladeria.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = Warehouse::firstOrFail();
    $this->producto = Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1', 'track_stock' => true]);

    // Existencia inicial y caja abierta: sin ellas no se puede cobrar.
    app(StockService::class)->increase(
        $this->producto, $this->warehouse,
        StockMovementType::Initial, '10',
    );

    // El alta de una empresa crea sucursal y almacén, pero no caja registradora.
    $this->caja = app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0', $this->owner->id);
});

/** Cobra una venta de $unidades en efectivo y devuelve la venta creada. */
function venderUnidades(int $unidades): Sale
{
    return app(CheckoutService::class)->checkout(
        test()->caja->fresh(),
        new CreateSaleData(
            warehouseId: (int) test()->warehouse->id,
            lines: [new SaleLineData(
                productId: (int) test()->producto->id,
                quantity: (string) $unidades,
                unitPrice: '100',
            )],
            paymentMethod: PaymentMethod::Cash,
        ),
    );
}

/** Existencia actual del producto. */
function existencia(): float
{
    return (float) DB::table('stock')->where('product_id', test()->producto->id)->sum('quantity');
}

it('anular devuelve el stock al inventario', function (): void {
    venderUnidades(3);
    expect(existencia())->toBe(7.0);

    $this->actingAs($this->owner)
        ->delete(route('panel.sales.bulk-void'), ['ids' => [Sale::firstOrFail()->id]])
        ->assertRedirect();

    expect(existencia())->toBe(10.0);
});

it('anular saca el cobro de la caja', function (): void {
    // Si el dinero se quedara, el arqueo esperaría un efectivo que ya no corresponde a nada.
    $venta = venderUnidades(1);

    expect(DB::table('cash_movements')->where('reference_id', $venta->id)->count())->toBe(1);

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);

    expect(DB::table('cash_movements')->where('reference_id', $venta->id)->count())->toBe(0);
});

/*
 * El dinero de una venta se apunta en DOS libros: el cajón del turno y la cuenta de la empresa.
 *
 * Durante un tiempo anular solo deshacía el primero. El saldo de la cuenta se quedaba con el ingreso
 * de ventas que ya no existían, y la desviación se acumulaba con cada anulación hasta que alguien
 * intentaba cuadrar contra el banco. No lo cazó ningún test porque los de arriba miran el cajón, que
 * sí se limpiaba.
 */

it('anular retira el ingreso de la cuenta de la empresa', function (): void {
    $cuenta = Account::query()->where('is_default', true)->firstOrFail();
    expect((string) $cuenta->fresh()->balance)->toBe('0.00');

    $venta = venderUnidades(3);

    // La venta entró: 3 × 100.
    expect((string) $cuenta->fresh()->balance)->toBe('300.00')
        ->and(DB::table('financial_movements')->where('reference_id', $venta->id)->count())->toBe(1);

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);

    expect((string) $cuenta->fresh()->balance)->toBe('0.00')
        ->and(DB::table('financial_movements')->where('reference_id', $venta->id)->count())->toBe(0);
});

it('vender y anular en bucle no desvía el saldo ni un céntimo', function (): void {
    // El error de signo natural —sumar el importe en vez de restarlo, al leer «devolver el dinero»—
    // duplicaría el ingreso en cada vuelta en lugar de deshacerlo.
    $cuenta = Account::query()->where('is_default', true)->firstOrFail();

    foreach ([1, 2, 1] as $unidades) {
        $venta = venderUnidades($unidades);
        $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);
    }

    expect((string) $cuenta->fresh()->balance)->toBe('0.00')
        ->and(DB::table('financial_movements')->count())->toBe(0);
});

it('después de anular se puede seguir vendiendo', function (): void {
    /*
     * El fallo más grave que ha tenido este flujo, y el que menos se veía venir.
     *
     * El código de la venta se saca contando ventas, y las anuladas se ARCHIVAN. Al no contarlas, la
     * siguiente venta reutilizaba el código de la anulada y chocaba contra el índice único de
     * `(company_id, code)`: la venta no se guardaba. Es decir, anulabas una venta y el punto de
     * venta dejaba de cobrar.
     *
     * No lo cazó ningún test porque todos anulaban al final, nunca antes de volver a vender.
     */
    $primera = venderUnidades(1);
    expect($primera->code)->toBe('V-000001');

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$primera->id]]);

    $segunda = venderUnidades(1);

    expect($segunda->code)->toBe('V-000002')
        ->and($segunda->exists)->toBeTrue();
});

it('archiva la venta en vez de destruirla', function (): void {
    // El borrado es lógico: la fila sigue en la base y se puede recuperar si fue un error.
    $venta = venderUnidades(1);

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);

    expect(Sale::find($venta->id))->toBeNull()
        ->and(Sale::withTrashed()->find($venta->id))->not->toBeNull();
});

it('anula varias a la vez y suma bien el stock devuelto', function (): void {
    $a = venderUnidades(2);
    $b = venderUnidades(3);
    expect(existencia())->toBe(5.0);

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$a->id, $b->id]]);

    expect(existencia())->toBe(10.0)
        ->and(Sale::count())->toBe(0);
});

it('NO anula una venta con factura fiscal, y lo dice', function (): void {
    // Un NCF emitido se reporta a la DGII: hacer desaparecer la venta dejaría un comprobante
    // declarado sin nada que lo respalde.
    $venta = venderUnidades(1);

    DB::table('invoices')->insert([
        'company_id' => $this->company->id, 'sale_id' => $venta->id,
        'ncf' => 'B0100000001', 'type' => 'B01', 'status' => 'issued',
        'subtotal' => '100', 'tax' => '18', 'total' => '118',
        'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);

    expect(Sale::find($venta->id))->not->toBeNull()
        ->and(existencia())->toBe(9.0) // el stock NO se devolvió
        ->and(session('panel_error'))->toContain('factura fiscal');
});

it('NO anula una venta cuyo arqueo ya se cerró', function (): void {
    // Sacar el cobro reescribiría un arqueo firmado, que es el documento que dice cuánto dinero
    // había al cerrar la caja.
    $venta = venderUnidades(1);
    app(CashService::class)->close($this->caja->fresh(), '100');

    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]]);

    expect(Sale::find($venta->id))->not->toBeNull()
        ->and(session('panel_error'))->toContain('arqueo');
});

it('anula las que puede y avisa de las que no', function (): void {
    // Una venta bloqueada no puede impedir que se anulen las demás.
    $buena = venderUnidades(1);
    $facturada = venderUnidades(1);

    DB::table('invoices')->insert([
        'company_id' => $this->company->id, 'sale_id' => $facturada->id,
        'ncf' => 'B0100000002', 'type' => 'B01', 'status' => 'issued',
        'subtotal' => '100', 'tax' => '18', 'total' => '118',
        'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->delete(route('panel.sales.bulk-void'), ['ids' => [$buena->id, $facturada->id]]);

    expect(Sale::find($buena->id))->toBeNull()
        ->and(Sale::find($facturada->id))->not->toBeNull()
        ->and(session('panel_ok'))->toContain('factura fiscal');
});

it('un cajero no puede anular ventas', function (): void {
    // Quien cobra no deshace cobros.
    $venta = venderUnidades(1);

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@heladeria.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)
        ->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id]])
        ->assertForbidden();

    expect(Sale::find($venta->id))->not->toBeNull();
});

it('no toca las ventas de otra empresa aunque se envíen sus ids', function (): void {
    $venta = venderUnidades(1);

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $ajena = Sale::create([
        'company_id' => $otra->id, 'code' => 'V-999', 'status' => 'completed',
        'warehouse_id' => Warehouse::firstOrFail()->id, // el almacén propio de la otra empresa
        'subtotal' => '10', 'tax' => '0', 'total' => '10', 'payment_method' => 'cash',
    ]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)
        ->delete(route('panel.sales.bulk-void'), ['ids' => [$venta->id, $ajena->id]]);

    app(CurrentCompany::class)->set($otra->id);
    expect(Sale::find($ajena->id))->not->toBeNull();
});

it('avisa si no se seleccionó nada', function (): void {
    $this->actingAs($this->owner)->delete(route('panel.sales.bulk-void'), ['ids' => []]);

    expect(session('panel_error'))->toContain('No se seleccionó ninguna venta');
});
