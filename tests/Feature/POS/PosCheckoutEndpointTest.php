<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'POS Endpoint Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Cajero',
        'email' => 'cajero@pos.test',
        'password' => 'secret-password',
    ]));

    app(CurrentCompany::class)->set($this->company->id);
    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Prod', 'cost' => '10', 'price' => '50']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');

    $register = CashRegister::create(['name' => 'Caja 1', 'is_active' => true]);
    $this->session = app(CashService::class)->open($register, '0');
});

it('cobra una venta desde el endpoint del POS', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 2]]),
            'paid' => '200',
            'customer_name' => 'Cliente POS',
        ])
        ->assertRedirect();

    expect(Sale::count())->toBe(1);

    $sale = Sale::firstOrFail();
    expect($sale->total)->toBe('100.00')
        ->and($sale->change)->toBe('100.00');

    $stock = Stock::where('product_id', $this->product->id)->firstOrFail();
    expect($stock->quantity)->toBe('98.000');

    expect(CashMovement::where('cash_session_id', $this->session->id)->count())->toBe(1);
});

it('la venta cobrada en el POS queda enlazada al cliente del CRM', function (): void {
    // Regresión: CheckoutService reconstruía el DTO a mano y perdía customerId, así que el selector
    // de cliente del POS no tenía ningún efecto. Este test cobra POR EL ENDPOINT a propósito: los
    // que llamaban a SaleService directamente se saltaban el checkout y por eso no lo detectaron.
    $customer = Customer::create(['name' => 'Ana Cliente', 'phone' => '18095551234']);

    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1,
        'range_from' => 1, 'range_to' => 1000, 'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 1]]),
            'paid' => '50',
            'customer_id' => $customer->id,
            'invoice' => '1',
        ])
        ->assertRedirect();

    $sale = Sale::firstOrFail();
    $invoice = Invoice::firstOrFail();

    expect($sale->customer_id)->toBe($customer->id)
        // La factura hereda el cliente de la venta: si la venta lo perdía, la factura también.
        ->and($invoice->customer_id)->toBe($customer->id);
});

it('rechaza un cobro con un cliente de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Co'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Customer::create(['name' => 'Cliente Ajeno']);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 1]]),
            'paid' => '50',
            'customer_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('customer_id');

    expect(Sale::count())->toBe(0);
});

it('cobra un ticket con descuento, propina, serie, nota y empleado', function (): void {
    $emp = Employee::create(['name' => 'María', 'is_active' => true]);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([[
                'id' => $this->product->id, 'qty' => 2, 'discount' => 10,
                'note' => 'sin caja', 'serial' => 'SN-9', 'employee_id' => $emp->id,
            ]]),
            'paid' => '1000',
            'tip' => '15',
            'discount_total' => '5',
            'employee_id' => $emp->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('pos_receipt_id');

    $sale = Sale::with('items')->firstOrFail();
    $item = $sale->items->first();

    // 2×50 − 10 = 90 de línea; − 5 de descuento global = 85 base; + 15 propina = 100 total.
    expect($item->discount)->toBe('10.00')
        ->and($item->subtotal)->toBe('90.00')
        ->and($item->serial)->toBe('SN-9')
        ->and($item->note)->toBe('sin caja')
        ->and($item->employee_id)->toBe($emp->id)
        ->and($sale->tip)->toBe('15.00')
        ->and($sale->discount_total)->toBe('5.00')
        ->and($sale->employee_id)->toBe($emp->id)
        ->and($sale->total)->toBe('100.00');
});

it('ignora el precio enviado por el cliente y usa el del producto', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            // Un cliente malicioso manda precio 1; el servidor debe usar 50.
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 1, 'price' => 1]]),
            'paid' => '50',
        ])
        ->assertRedirect();

    expect(Sale::firstOrFail()->total)->toBe('50.00');
});

it('rechaza un empleado de otra empresa en la venta', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Emp'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Employee::create(['name' => 'Ajeno', 'is_active' => true]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 1]]),
            'paid' => '50',
            'employee_id' => $ajeno->id,
        ])
        ->assertSessionHasErrors('employee_id');

    expect(Sale::count())->toBe(0);
});

it('rechaza el cobro si no hay stock suficiente', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 999]]),
            'paid' => '999999',
        ])
        ->assertRedirect();

    expect(Sale::count())->toBe(0)
        ->and(Stock::where('product_id', $this->product->id)->firstOrFail()->quantity)->toBe('100.000');
});

// ------------------------------------------------------------------ Los interruptores del terminal

/*
 * QUE APAGAR EL DESCUENTO IMPIDA DESCONTAR DE VERDAD.
 *
 * Los interruptores del terminal solo escondían controles en la pantalla; ninguna de las tres
 * pantallas de cobro los comprobaba al recibir la venta. Apagar «descuento» no impedía nada: bastaba
 * una pestaña abierta de antes del cambio, una venta sin conexión sincronizada después, o una
 * petición hecha a mano. Y un descuento es dinero que SALE del negocio sin que nadie lo autorice.
 *
 * Es la misma doctrina que ya rige el precio, que siempre se relee del catálogo: el navegador
 * propone, el servidor decide.
 *
 * SE IGNORA, NO SE RECHAZA: casi nunca es un ataque, es una pantalla vieja. Devolver un error haría
 * perder el ticket entero por un campo que el negocio ya no usa.
 */
it('con el descuento apagado, el que llega en la peticion se ignora', function (): void {
    $this->company->update(['settings' => ['pos' => ['profile' => 'general', 'options' => [
        'line_discount' => false,
        'global_discount' => false,
    ]]]]);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 2, 'discount' => 10]]),
            'paid' => '1000',
            'discount_total' => '5',
        ])
        ->assertRedirect()
        ->assertSessionHas('pos_receipt_id');

    $sale = Sale::with('items')->firstOrFail();

    // 2×50 sin una sola rebaja: ni la de línea ni la del ticket.
    expect($sale->items->first()->discount)->toBe('0.00')
        ->and($sale->items->first()->subtotal)->toBe('100.00')
        ->and($sale->discount_total)->toBe('0.00')
        ->and($sale->total)->toBe('100.00');
});

/*
 * Y encendido, sigue descontando. Sin esta mitad, el test de arriba pasaría igual si alguien rompiera
 * el descuento entero.
 */
it('con el descuento encendido sigue descontando', function (): void {
    $this->company->update(['settings' => ['pos' => ['profile' => 'general', 'options' => [
        'line_discount' => true,
        'global_discount' => true,
    ]]]]);

    $this->actingAs($this->user)
        ->post(route('panel.pos.checkout'), [
            'cart' => json_encode([['id' => $this->product->id, 'qty' => 2, 'discount' => 10]]),
            'paid' => '1000',
            'discount_total' => '5',
        ])
        ->assertRedirect();

    $sale = Sale::with('items')->firstOrFail();

    expect($sale->items->first()->discount)->toBe('10.00')
        ->and($sale->discount_total)->toBe('5.00')
        ->and($sale->total)->toBe('85.00');
});
