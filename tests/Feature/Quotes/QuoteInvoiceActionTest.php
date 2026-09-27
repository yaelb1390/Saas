<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Quotes\Services\QuoteService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * "Cobrar y facturar", de un clic: encadena QuoteConverter (ya probado en QuoteConversionTest) e
 * InvoiceService (ya probado en InvoiceServiceTest) SIN reescribir ninguno de los dos. Lo único
 * propio de aquí es que si el segundo paso falla, el primero ya quedó hecho y a salvo: no hay que
 * cobrar dos veces para poder facturar.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería El Progreso'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@cotizar.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'CEM', 'name' => 'Cemento', 'cost' => '300', 'price' => '450']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '20');

    app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');

    $this->quote = app(QuoteService::class)->crear(
        [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '450']],
        ['customer_name' => 'Juan', 'customer_phone' => '18095551234'],
    );
});

it('cobra la cotización y emite el comprobante en un solo paso', function (): void {
    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1, 'range_from' => 1, 'range_to' => 1000,
        'number_length' => 8, 'is_active' => true,
    ]);

    $respuesta = $this->actingAs($this->owner)->post(route('panel.quotes.invoice', $this->quote), [
        'payment_method' => 'cash',
        'paid' => (string) $this->quote->total,
        'type' => NcfType::Consumo->value,
    ]);

    $respuesta->assertRedirect(route('panel.quotes.show', $this->quote));

    $factura = Invoice::query()->firstOrFail();

    expect($factura->ncf)->toBe('B0200000001')
        ->and((string) $factura->total)->toBe((string) $this->quote->total)
        ->and(Sale::query()->count())->toBe(1)
        ->and($this->quote->fresh()->status->value)->toBe('converted');
});

it('si falla la emisión del NCF, la venta queda registrada igual (no se pierde el cobro)', function (): void {
    // Sin secuencia activa: InvoiceService no puede emitir, pero QuoteConverter ya cobró.
    $respuesta = $this->actingAs($this->owner)->post(route('panel.quotes.invoice', $this->quote), [
        'payment_method' => 'cash',
        'paid' => (string) $this->quote->total,
        'type' => NcfType::Consumo->value,
    ]);

    $respuesta->assertRedirect(route('panel.quotes.show', $this->quote));
    expect(session('panel_error'))->toContain(Sale::query()->firstOrFail()->code)
        ->and(Sale::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(0)
        // La venta SÍ quedó marcada como cobrada: no hay que volver a cobrarle al cliente.
        ->and($this->quote->fresh()->status->value)->toBe('converted');
});

it('un usuario sin permiso de facturar no puede usar esta acción', function (): void {
    // Sin ningún rol asignado, sin ningún permiso: es la misma comprobación que ya usa
    // PartsCounterTest para "sin permiso invoices.issue no accede al mostrador".
    $sinPermiso = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'sinpermiso@cotizar.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($sinPermiso)->post(route('panel.quotes.invoice', $this->quote), [
        'type' => NcfType::Consumo->value,
    ])->assertForbidden();

    expect(Sale::query()->count())->toBe(0);
});
