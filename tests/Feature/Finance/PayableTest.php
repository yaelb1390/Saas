<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Finance\Enums\PayableStatus;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\PayableService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use App\Modules\Purchasing\DTOs\PurchaseOrderLineData;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cuentas por pagar: lo que el negocio todavía le debe a un proveedor.
 *
 * Nace sola al recibir una orden de compra (el evento PurchaseOrderReceived que su propio docblock
 * ya anticipaba como "punto de enganche para cuentas por pagar"). El pago toca hasta dos sitios,
 * igual que un Gasto: la cuenta siempre, y el cajón si es efectivo con turno abierto.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cuentas Co'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@cxp.test', 'password' => 'secret-password',
    ]), 'owner');
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '20']);
    $this->supplier = Supplier::create(['name' => 'Distribuidora XYZ']);
    $this->cuentaFinanciera = Account::query()->where('is_default', true)->firstOrFail();
});

function ordenRecibida(string $cantidad = '10', string $costo = '10'): PurchaseOrder
{
    $orden = app(PurchaseOrderService::class)->create(new CreatePurchaseOrderData(
        supplierId: test()->supplier->id,
        warehouseId: test()->warehouse->id,
        lines: [new PurchaseOrderLineData(productId: test()->product->id, quantity: $cantidad, unitCost: $costo)],
    ));

    return app(PurchaseOrderService::class)->receive($orden);
}

// ------------------------------------------------------------------ Nace sola de la orden recibida

it('recibir una orden de compra genera su cuenta por pagar', function (): void {
    $orden = ordenRecibida('10', '10');

    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    expect($cuenta->total)->toBe('100.00')
        ->and($cuenta->balance)->toBe('100.00')
        ->and($cuenta->status)->toBe(PayableStatus::Pending)
        ->and($cuenta->supplier_name)->toBe('Distribuidora XYZ')
        ->and($cuenta->code)->toBe('CXP-000001');
});

it('el vencimiento por defecto son 30 días si el proveedor no tiene su propio plazo', function (): void {
    $orden = ordenRecibida();

    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    expect($cuenta->due_date->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('respeta el plazo de crédito propio del proveedor', function (): void {
    $this->supplier->update(['payment_terms_days' => 45]);

    $orden = ordenRecibida();

    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    expect($cuenta->due_date->toDateString())->toBe(now()->addDays(45)->toDateString());
});

it('recibir dos veces la misma orden no duplica la cuenta por pagar', function (): void {
    $orden = app(PurchaseOrderService::class)->create(new CreatePurchaseOrderData(
        supplierId: $this->supplier->id,
        warehouseId: $this->warehouse->id,
        lines: [new PurchaseOrderLineData(productId: $this->product->id, quantity: '5', unitCost: '10')],
    ));

    // El servicio de compras ya impide recibir dos veces la MISMA orden (ver
    // PurchaseOrderReceivingTest), así que se comprueba directamente el guardián del servicio de
    // cuentas por pagar: da igual quién dispare el evento dos veces, la cuenta no se duplica.
    app(PayableService::class)->crearDesdeOrden($orden->fresh());

    expect(fn () => app(PayableService::class)->crearDesdeOrden($orden->fresh()))
        ->toThrow(FinanceException::class);

    expect(Payable::query()->where('purchase_order_id', $orden->id)->count())->toBe(1);
});

// ------------------------------------------------------------------ Abonos

it('un pago baja el saldo y, si lo cubre todo, la deja saldada', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    app(PayableService::class)->registerPayment($cuenta, '60', ['account_id' => $this->cuentaFinanciera->id]);
    $cuenta->refresh();

    expect($cuenta->balance)->toBe('40.00')
        ->and($cuenta->status)->toBe(PayableStatus::Partial);

    app(PayableService::class)->registerPayment($cuenta, '40', ['account_id' => $this->cuentaFinanciera->id]);
    $cuenta->refresh();

    expect($cuenta->balance)->toBe('0.00')
        ->and($cuenta->status)->toBe(PayableStatus::Paid);
});

it('no se puede pagar más del saldo pendiente', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    expect(fn () => app(PayableService::class)->registerPayment($cuenta, '999', ['account_id' => $this->cuentaFinanciera->id]))
        ->toThrow(FinanceException::class);
});

it('una cuenta ya saldada no admite más pagos', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();
    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(PayableService::class)->registerPayment($cuenta->refresh(), '1', ['account_id' => $this->cuentaFinanciera->id]))
        ->toThrow(FinanceException::class);
});

it('el pago se anota como egreso en la cuenta financiera elegida', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();
    $saldoInicial = (string) $this->cuentaFinanciera->balance;

    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect((string) $this->cuentaFinanciera->refresh()->balance)->toBe(bcsub($saldoInicial, '100', 2))
        ->and(FinancialMovement::query()->where('account_id', $this->cuentaFinanciera->id)->where('amount', '-100.00')->exists())->toBeTrue();
});

it('un pago en efectivo con turno abierto también se descuenta del cajón', function (): void {
    $caja = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
    app(CashService::class)->open($caja, '500', $this->owner->id);

    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(CashMovement::query()->where('type', CashMovementType::Expense)->where('amount', '-100.00')->exists())->toBeTrue();
});

it('sin turno de caja abierto, el pago igual se anota en la cuenta (sin tocar el cajón)', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(CashMovement::query()->count())->toBe(0)
        ->and($cuenta->refresh()->status)->toBe(PayableStatus::Paid);
});

// ------------------------------------------------------------------ Alta manual

it('se puede dar de alta una cuenta suelta que no viene de una orden de compra', function (): void {
    $cuenta = app(PayableService::class)->crear([
        'supplier_name' => 'Deuda vieja migrada',
        'total' => '500',
    ]);

    expect($cuenta->purchase_order_id)->toBeNull()
        ->and($cuenta->total)->toBe('500.00')
        ->and($cuenta->balance)->toBe('500.00');
});

// ------------------------------------------------------------------ Editar y eliminar

it('se puede editar el monto mientras no tenga abonos', function (): void {
    $cuenta = app(PayableService::class)->crear(['supplier_name' => 'Proveedor', 'total' => '500']);

    app(PayableService::class)->actualizar($cuenta, ['total' => '800', 'notes' => 'Ajustado']);
    $cuenta->refresh();

    expect($cuenta->total)->toBe('800.00')
        ->and($cuenta->balance)->toBe('800.00')
        ->and($cuenta->notes)->toBe('Ajustado');
});

it('no se puede editar el monto si ya tiene abonos', function (): void {
    $cuenta = app(PayableService::class)->crear(['supplier_name' => 'Proveedor', 'total' => '500']);
    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(PayableService::class)->actualizar($cuenta->refresh(), ['total' => '800']))
        ->toThrow(FinanceException::class);
});

it('se puede eliminar una cuenta sin abonos', function (): void {
    $cuenta = app(PayableService::class)->crear(['supplier_name' => 'Proveedor', 'total' => '500']);

    app(PayableService::class)->eliminar($cuenta);

    expect(Payable::query()->count())->toBe(0)
        ->and(Payable::withTrashed()->count())->toBe(1);
});

it('no se puede eliminar una cuenta con abonos', function (): void {
    $cuenta = app(PayableService::class)->crear(['supplier_name' => 'Proveedor', 'total' => '500']);
    app(PayableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(PayableService::class)->eliminar($cuenta->refresh()))
        ->toThrow(FinanceException::class);

    expect(Payable::query()->count())->toBe(1);
});

it('editar y eliminar desde el panel exigen finance.manage', function (): void {
    $cuenta = app(PayableService::class)->crear(['supplier_name' => 'Proveedor', 'total' => '500']);

    $this->actingAs($this->owner)->put(route('panel.payables.update', $cuenta), [
        'total' => '600',
    ])->assertRedirect();

    expect($cuenta->refresh()->total)->toBe('600.00');

    $this->actingAs($this->owner)->delete(route('panel.payables.destroy', $cuenta))->assertRedirect(route('panel.payables'));

    expect(Payable::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ Aislamiento multiempresa

it('las cuentas por pagar de otra empresa no se ven', function (): void {
    ordenRecibida();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    app(CurrentCompany::class)->set($otra->id);

    expect(Payable::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ HTTP

it('la pantalla de detalle renderiza con el anillo de progreso y el formulario de pago', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.payables.show', $cuenta));

    $respuesta->assertOk();
    expect($respuesta->content())->toContain($cuenta->code)
        ->and($respuesta->content())->toContain('Resumen Financiero y Documental')
        ->and($respuesta->content())->toContain('Registrar Pago Actual')
        ->and($respuesta->content())->toContain('0%')
        ->and($respuesta->content())->toContain('Tarjeta de crédito');
});

it('una cuenta parcialmente pagada muestra el porcentaje real en el anillo', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();
    app(PayableService::class)->registerPayment($cuenta, '25', ['account_id' => $this->cuentaFinanciera->id]);

    $respuesta = $this->actingAs($this->owner)->get(route('panel.payables.show', $cuenta->fresh()));

    $respuesta->assertOk()->assertSee('25%');
});

it('finance.view puede ver el listado y finance.manage puede pagar', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    $this->actingAs($this->owner)->get(route('panel.payables'))->assertOk();

    $this->actingAs($this->owner)->post(route('panel.payables.pay', $cuenta), [
        'account_id' => $this->cuentaFinanciera->id,
        'amount' => '100',
    ])->assertRedirect();

    expect($cuenta->refresh()->status)->toBe(PayableStatus::Paid);
});

it('se puede exportar el listado a CSV', function (): void {
    ordenRecibida();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.export.payables'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('text/csv')
        ->and($respuesta->streamedContent())->toContain('CXP-000001');
});

it('se puede exportar el listado a Excel, como tabla', function (): void {
    ordenRecibida();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.export.payables', ['format' => 'xlsx']));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('spreadsheetml');
});

it('un usuario sin permiso no puede pagar', function (): void {
    $orden = ordenRecibida('10', '10');
    $cuenta = Payable::query()->where('purchase_order_id', $orden->id)->firstOrFail();

    $sinPermiso = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'sinpermiso@cxp.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinPermiso)->post(route('panel.payables.pay', $cuenta), [
        'account_id' => $this->cuentaFinanciera->id,
        'amount' => '100',
    ])->assertForbidden();
});
