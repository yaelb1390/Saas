<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Finance\Enums\ReceivableStatus;
use App\Modules\Finance\Exceptions\FinanceException;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialMovement;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\ReceivableService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\PaymentData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cuentas por cobrar: lo que un cliente todavía debe.
 *
 * Nace sola de una venta a crédito (el mismo evento SaleCompleted que ya usa RecordSaleIncome para
 * anotar SOLO lo cobrado); lo que falta por cobrar es exactamente el hueco que faltaba. El motor de
 * abonos calca LoanService: saldo con bcmath, nunca en negativo, nunca por encima de sí mismo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Cuentas Co'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@cxc.test', 'password' => 'secret-password',
    ]), 'owner');
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '50', 'price' => '100']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');
    $this->cuentaFinanciera = Account::query()->where('is_default', true)->firstOrFail();
});

function ventaAlContado(): \App\Modules\Sales\Models\Sale
{
    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(productId: test()->product->id, quantity: '1', unitPrice: '100')],
        paid: '100',
    ));
}

function ventaACredito(string $pagadoAhora = '0'): \App\Modules\Sales\Models\Sale
{
    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(productId: test()->product->id, quantity: '1', unitPrice: '100')],
        paymentMethod: PaymentMethod::Credit,
        paid: $pagadoAhora,
        customerName: 'Juan a crédito',
    ));
}

// ------------------------------------------------------------------ Nace sola de la venta

it('una venta de contado no genera cuenta por cobrar', function (): void {
    ventaAlContado();

    expect(Receivable::query()->count())->toBe(0);
});

it('una venta a crédito genera su cuenta por cobrar con el saldo pendiente', function (): void {
    $venta = ventaACredito('0');

    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    expect($cuenta->total)->toBe('100.00')
        ->and($cuenta->balance)->toBe('100.00')
        ->and($cuenta->status)->toBe(ReceivableStatus::Pending)
        ->and($cuenta->customer_name)->toBe('Juan a crédito')
        ->and($cuenta->code)->toBe('CXC-000001');
});

it('una venta cobrada a medias (cobro repartido) genera una cuenta solo por lo que falta', function (): void {
    // «paid» a secas con payment_method=credit NO es un cobro parcial: toda la venta se cuenta
    // como sin cobrar (ver LectorDePagos::desdeLaCabecera, «a crédito no se recibió nada»). Un
    // cobro de verdad mitad-mitad usa el reparto (`payments`), que es como el POS lo hace real.
    $venta = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '1', unitPrice: '100')],
        customerName: 'Juan mixto',
        payments: [
            new PaymentData(method: PaymentMethod::Cash, amount: '40'),
            new PaymentData(method: PaymentMethod::Credit, amount: '60'),
        ],
    ));

    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    expect($cuenta->total)->toBe('60.00')
        ->and($cuenta->balance)->toBe('60.00');
});

it('el vencimiento por defecto son 30 días si el cliente no tiene su propio plazo', function (): void {
    $venta = ventaACredito();

    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    expect($cuenta->due_date->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('respeta el plazo de crédito propio del cliente', function (): void {
    $cliente = Customer::create(['name' => 'Cliente con plazo', 'payment_terms_days' => 15]);

    app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '1', unitPrice: '100')],
        paymentMethod: PaymentMethod::Credit,
        paid: '0',
        customerId: $cliente->id,
    ));

    $cuenta = Receivable::query()->where('customer_id', $cliente->id)->firstOrFail();

    expect($cuenta->due_date->toDateString())->toBe(now()->addDays(15)->toDateString());
});

// ------------------------------------------------------------------ Abonos

it('un abono baja el saldo y, si lo cubre todo, la deja saldada', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    app(ReceivableService::class)->registerPayment($cuenta, '60', ['account_id' => $this->cuentaFinanciera->id]);
    $cuenta->refresh();

    expect($cuenta->balance)->toBe('40.00')
        ->and($cuenta->status)->toBe(ReceivableStatus::Partial);

    app(ReceivableService::class)->registerPayment($cuenta, '40', ['account_id' => $this->cuentaFinanciera->id]);
    $cuenta->refresh();

    expect($cuenta->balance)->toBe('0.00')
        ->and($cuenta->status)->toBe(ReceivableStatus::Paid);
});

it('no se puede abonar más del saldo pendiente', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    expect(fn () => app(ReceivableService::class)->registerPayment($cuenta, '999', ['account_id' => $this->cuentaFinanciera->id]))
        ->toThrow(FinanceException::class);
});

it('un monto de cero o negativo se rechaza', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    expect(fn () => app(ReceivableService::class)->registerPayment($cuenta, '0', ['account_id' => $this->cuentaFinanciera->id]))
        ->toThrow(FinanceException::class);
});

it('una cuenta ya saldada no admite más abonos', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();
    app(ReceivableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(ReceivableService::class)->registerPayment($cuenta->refresh(), '1', ['account_id' => $this->cuentaFinanciera->id]))
        ->toThrow(FinanceException::class);
});

it('el cobro se anota como ingreso en la cuenta financiera elegida', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();
    $saldoInicial = (string) $this->cuentaFinanciera->balance;

    app(ReceivableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect((string) $this->cuentaFinanciera->refresh()->balance)->toBe(bcadd($saldoInicial, '100', 2))
        ->and(FinancialMovement::query()->where('account_id', $this->cuentaFinanciera->id)->where('amount', '100.00')->exists())->toBeTrue();
});

// ------------------------------------------------------------------ Alta manual

it('se puede dar de alta una cuenta suelta que no viene de una venta', function (): void {
    $cuenta = app(ReceivableService::class)->crear([
        'customer_name' => 'Deuda vieja migrada',
        'total' => '500',
    ]);

    expect($cuenta->sale_id)->toBeNull()
        ->and($cuenta->total)->toBe('500.00')
        ->and($cuenta->balance)->toBe('500.00');
});

// ------------------------------------------------------------------ Editar y eliminar

it('se puede editar el monto mientras no tenga abonos', function (): void {
    $cuenta = app(ReceivableService::class)->crear(['customer_name' => 'Cliente', 'total' => '500']);

    app(ReceivableService::class)->actualizar($cuenta, ['total' => '800', 'notes' => 'Ajustado']);
    $cuenta->refresh();

    expect($cuenta->total)->toBe('800.00')
        ->and($cuenta->balance)->toBe('800.00')
        ->and($cuenta->notes)->toBe('Ajustado');
});

it('no se puede editar el monto si ya tiene abonos', function (): void {
    $cuenta = app(ReceivableService::class)->crear(['customer_name' => 'Cliente', 'total' => '500']);
    app(ReceivableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(ReceivableService::class)->actualizar($cuenta->refresh(), ['total' => '800']))
        ->toThrow(FinanceException::class);
});

it('se puede eliminar una cuenta sin abonos', function (): void {
    $cuenta = app(ReceivableService::class)->crear(['customer_name' => 'Cliente', 'total' => '500']);

    app(ReceivableService::class)->eliminar($cuenta);

    expect(Receivable::query()->count())->toBe(0)
        ->and(Receivable::withTrashed()->count())->toBe(1);
});

it('no se puede eliminar una cuenta con abonos', function (): void {
    $cuenta = app(ReceivableService::class)->crear(['customer_name' => 'Cliente', 'total' => '500']);
    app(ReceivableService::class)->registerPayment($cuenta, '100', ['account_id' => $this->cuentaFinanciera->id]);

    expect(fn () => app(ReceivableService::class)->eliminar($cuenta->refresh()))
        ->toThrow(FinanceException::class);

    expect(Receivable::query()->count())->toBe(1);
});

it('editar y eliminar desde el panel exigen finance.manage', function (): void {
    $cuenta = app(ReceivableService::class)->crear(['customer_name' => 'Cliente', 'total' => '500']);

    $this->actingAs($this->owner)->put(route('panel.receivables.update', $cuenta), [
        'total' => '600',
    ])->assertRedirect();

    expect($cuenta->refresh()->total)->toBe('600.00');

    $this->actingAs($this->owner)->delete(route('panel.receivables.destroy', $cuenta))->assertRedirect(route('panel.receivables'));

    expect(Receivable::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ Aislamiento multiempresa

it('las cuentas por cobrar de otra empresa no se ven', function (): void {
    ventaACredito();

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    app(CurrentCompany::class)->set($otra->id);

    expect(Receivable::query()->count())->toBe(0);
});

// ------------------------------------------------------------------ HTTP

it('la pantalla de detalle renderiza con el anillo de progreso y el formulario de abono', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.receivables.show', $cuenta));

    $respuesta->assertOk();
    expect($respuesta->content())->toContain($cuenta->code)
        ->and($respuesta->content())->toContain('Resumen Financiero y Documental')
        ->and($respuesta->content())->toContain('Registrar Cobro Actual')
        ->and($respuesta->content())->toContain('0%') // nada abonado todavía
        ->and($respuesta->content())->toContain('Tarjeta de crédito');
});

it('una cuenta parcialmente abonada muestra el porcentaje real en el anillo', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();
    app(ReceivableService::class)->registerPayment($cuenta, '25', ['account_id' => $this->cuentaFinanciera->id]);

    $respuesta = $this->actingAs($this->owner)->get(route('panel.receivables.show', $cuenta->fresh()));

    $respuesta->assertOk()->assertSee('25%');
});

it('finance.view puede ver el listado y finance.manage puede abonar', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    $this->actingAs($this->owner)->get(route('panel.receivables'))->assertOk();

    $this->actingAs($this->owner)->post(route('panel.receivables.pay', $cuenta), [
        'account_id' => $this->cuentaFinanciera->id,
        'amount' => '100',
    ])->assertRedirect();

    expect($cuenta->refresh()->status)->toBe(ReceivableStatus::Paid);
});

it('se puede exportar el listado a CSV', function (): void {
    ventaACredito();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.export.receivables'));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('text/csv')
        ->and($respuesta->streamedContent())->toContain('CXC-000001');
});

it('se puede exportar el listado a Excel, como tabla', function (): void {
    ventaACredito();

    $respuesta = $this->actingAs($this->owner)->get(route('panel.export.receivables', ['format' => 'xlsx']));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('spreadsheetml');
});

it('un usuario sin permiso no puede abonar', function (): void {
    $venta = ventaACredito();
    $cuenta = Receivable::query()->where('sale_id', $venta->id)->firstOrFail();

    $sinPermiso = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'sinpermiso@cxc.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinPermiso)->post(route('panel.receivables.pay', $cuenta), [
        'account_id' => $this->cuentaFinanciera->id,
        'amount' => '100',
    ])->assertForbidden();
});
