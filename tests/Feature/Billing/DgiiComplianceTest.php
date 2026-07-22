<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\DgiiReportService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Support\TaxId;
use App\Modules\Billing\Support\TaxIdKind;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** RNC cuyo dígito verificador es correcto (calculado con los pesos oficiales). */
const RNC_VALIDO = '130123454';

/** Mismo número con el dígito verificador equivocado. */
const RNC_INVALIDO = '130123456';

/** Cédula con dígito verificador correcto (Luhn). */
const CEDULA_VALIDA = '00113918478';

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(
        name: 'Fiscal Co',
        taxId: '131234567',
    ));
    app(CurrentCompany::class)->set($this->company->id);

    $this->user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@fiscal.test', 'password' => 'secret-password',
    ]));

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'F1', 'name' => 'Producto', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '500');
});

function fiscalSale(): Sale
{
    return app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: test()->warehouse->id,
        lines: [new SaleLineData(productId: test()->product->id, quantity: '1', unitPrice: '100')],
        paid: '100000',
    ));
}

function sequenceFor(NcfType $type): FiscalSequence
{
    return FiscalSequence::create([
        'type' => $type,
        'next_number' => 1,
        'range_from' => 1,
        'range_to' => 1000,
        'number_length' => 8,
        'expires_at' => now()->addYear(),
        'is_active' => true,
    ]);
}

// ---------------------------------------------------------------- Validación de RNC/Cédula

it('valida el dígito verificador del RNC y de la cédula', function (): void {
    expect(TaxId::isValid(RNC_VALIDO))->toBeTrue()
        ->and(TaxId::isValid(RNC_INVALIDO))->toBeFalse()
        ->and(TaxId::isValid(CEDULA_VALIDA))->toBeTrue()
        ->and(TaxId::isValid('123'))->toBeFalse()
        ->and(TaxId::isValid(null))->toBeFalse();

    expect(TaxId::tryParse(RNC_VALIDO)?->kind)->toBe(TaxIdKind::Rnc)
        ->and(TaxId::tryParse(CEDULA_VALIDA)?->kind)->toBe(TaxIdKind::Cedula);
});

// ---------------------------------------------------------------- Reglas de emisión

it('el crédito fiscal exige el RNC del cliente', function (): void {
    $sale = fiscalSale();
    sequenceFor(NcfType::CreditoFiscal);

    expect(fn () => app(InvoiceService::class)->issueForSale($sale, NcfType::CreditoFiscal))
        ->toThrow(InvoiceException::class);

    // El NCF no se consume si la emisión falla: la secuencia sigue intacta.
    expect(FiscalSequence::firstOrFail()->next_number)->toBe(1)
        ->and(Invoice::count())->toBe(0);
});

it('rechaza un RNC con dígito verificador inválido', function (): void {
    $sale = fiscalSale();
    sequenceFor(NcfType::CreditoFiscal);

    expect(fn () => app(InvoiceService::class)->issueForSale($sale, NcfType::CreditoFiscal, RNC_INVALIDO))
        ->toThrow(InvoiceException::class);
});

it('emite crédito fiscal con un RNC válido', function (): void {
    $sale = fiscalSale();
    sequenceFor(NcfType::CreditoFiscal);

    $invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::CreditoFiscal, RNC_VALIDO);

    expect($invoice->ncf)->toBe('B0100000001')
        ->and($invoice->customer_tax_id)->toBe(RNC_VALIDO)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);
});

it('el comprobante de consumo no exige RNC (consumidor final)', function (): void {
    $sale = fiscalSale();
    sequenceFor(NcfType::Consumo);

    $invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);

    expect($invoice->ncf)->toBe('B0200000001')
        ->and($invoice->customer_tax_id)->toBeNull();
});

// ---------------------------------------------------------------- Anulación

it('anula un comprobante y no reutiliza su NCF', function (): void {
    sequenceFor(NcfType::Consumo);
    $invoice = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);

    app(InvoiceService::class)->cancel($invoice, CancellationReason::DevolucionProductos, 'Cliente devolvió el producto');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->cancellation_code)->toBe(CancellationReason::DevolucionProductos)
        ->and($invoice->cancelled_at)->not->toBeNull();

    // El siguiente comprobante toma el número siguiente: el NCF anulado queda inutilizado.
    $next = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);
    expect($next->ncf)->toBe('B0200000002');
});

it('no permite anular dos veces el mismo comprobante', function (): void {
    sequenceFor(NcfType::Consumo);
    $invoice = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);

    app(InvoiceService::class)->cancel($invoice, CancellationReason::CorreccionInformacion);

    expect(fn () => app(InvoiceService::class)->cancel($invoice->refresh(), CancellationReason::DuplicidadFactura))
        ->toThrow(InvoiceException::class);
});

// ---------------------------------------------------------------- Envíos 607 / 608

it('el 607 lista las ventas del período y excluye las anuladas', function (): void {
    sequenceFor(NcfType::Consumo);
    $vigente = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);
    $anulada = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);
    app(InvoiceService::class)->cancel($anulada, CancellationReason::DuplicidadFactura);

    $txt = app(DgiiReportService::class)->sales607(Carbon::now());
    $lines = explode("\r\n", trim($txt));

    // Cabecera: formato | RNC de la empresa | período | cantidad de registros.
    expect($lines[0])->toBe('607|131234567|'.now()->format('Ym').'|1')
        ->and($txt)->toContain((string) $vigente->ncf)
        ->and($txt)->not->toContain((string) $anulada->ncf);

    // La línea declara el ITBIS y el importe en la columna de efectivo.
    $columns = explode('|', $lines[1]);
    expect($columns[2])->toBe((string) $vigente->ncf)
        ->and($columns[5])->toBe(now()->format('Ymd'))
        ->and($columns[8])->toBe(number_format((float) $vigente->tax, 2, '.', ''));
});

it('el 608 lista los comprobantes anulados con su código', function (): void {
    sequenceFor(NcfType::Consumo);
    $invoice = app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);
    app(InvoiceService::class)->cancel($invoice, CancellationReason::DevolucionProductos);

    $txt = app(DgiiReportService::class)->cancelled608(Carbon::now());
    $lines = explode("\r\n", trim($txt));

    expect($lines[0])->toBe('608|131234567|'.now()->format('Ym').'|1')
        ->and($lines[1])->toBe($invoice->ncf.'|'.now()->format('Ymd').'|07');
});

// ---------------------------------------------------------------- Panel

it('emite y anula un comprobante desde el panel', function (): void {
    sequenceFor(NcfType::Consumo);
    $sale = fiscalSale();

    $this->actingAs($this->user)
        ->post(route('panel.invoices.issue'), [
            '_form' => 'invoice_issue',
            'sale_id' => $sale->id,
            'type' => 'B02',
        ])
        ->assertRedirect();

    $invoice = Invoice::firstOrFail();
    expect($invoice->ncf)->toBe('B0200000001');

    $this->actingAs($this->user)
        ->post(route('panel.invoices.cancel', $invoice), ['reason' => '05'])
        ->assertRedirect();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled);
});

it('informa el error de dominio sin romper la página cuando falta el RNC', function (): void {
    sequenceFor(NcfType::CreditoFiscal);
    $sale = fiscalSale();

    $this->actingAs($this->user)
        ->post(route('panel.invoices.issue'), [
            '_form' => 'invoice_issue',
            'sale_id' => $sale->id,
            'type' => 'B01',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Invoice::count())->toBe(0);
});

it('descarga el envío 607 del período', function (): void {
    sequenceFor(NcfType::Consumo);
    app(InvoiceService::class)->issueForSale(fiscalSale(), NcfType::Consumo);

    $response = $this->actingAs($this->user)
        ->get(route('panel.dgii.607', ['period' => now()->format('Y-m')]))
        ->assertOk();

    expect($response->streamedContent())->toContain('607|131234567|');
});

it('no permite anular un comprobante de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena Fiscal'));
    app(CurrentCompany::class)->set($other->id);

    $foreign = Invoice::create([
        'company_id' => $other->id, 'ncf' => 'B0200000099', 'type' => NcfType::Consumo,
        'subtotal' => '100', 'tax' => '18', 'total' => '118',
        'status' => InvoiceStatus::Issued, 'issued_at' => now(),
    ]);

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->post(route('panel.invoices.cancel', $foreign->id), ['reason' => '01'])
        ->assertNotFound();

    expect(Invoice::withoutGlobalScopes()->whereKey($foreign->id)->value('status'))->toBe(InvoiceStatus::Issued);
});
