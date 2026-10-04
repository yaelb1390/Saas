<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfStatus;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\EmissionMode;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoice;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use App\Modules\ElectronicInvoicing\Signature\CertificateVault;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Fase 5a: el e-CF sale de las ventas facturadas (Facturación, POS, Cotización → Factura, Mostrador),
 * todas por `InvoiceService::issueForSale`, según el modo de emisión de la empresa.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['filesystems.fiscal_documents' => 'local']);
    Storage::fake('local');

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    $this->company->forceFill(['modules' => null])->save();
    app(CurrentCompany::class)->set($this->company->id);

    $this->ajustes = ElectronicInvoicingSettings::paraEmpresa($this->company);
    $this->ajustes->forceFill(['tax_id' => '131000002', 'legal_name' => 'Colmado SRL', 'address' => 'Calle 1'])->save();

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->producto = Product::create(['sku' => 'R1', 'name' => 'Refresco', 'cost' => '50', 'price' => '118']);
    app(StockService::class)->increase($this->producto, $this->warehouse, StockMovementType::Purchase, '1000');

    $this->venta = fn (array $extra = []) => app(SaleService::class)->complete(new CreateSaleData(...array_merge([
        'warehouseId' => $this->warehouse->id,
        'lines' => [new SaleLineData(productId: $this->producto->id, quantity: '2', unitPrice: '118')],
        'paid' => '100000',
        'customerName' => 'Cliente SRL',
    ], $extra)));

    $this->serieB = fn (NcfType $tipo = NcfType::Consumo) => FiscalSequence::create([
        'type' => $tipo, 'next_number' => 1, 'range_from' => 1, 'range_to' => 1000, 'number_length' => 8, 'is_active' => true,
    ]);

    $this->serieE = fn (EcfType $tipo, Environment $env = Environment::Pruebas) => ElectronicNcfSequence::create([
        'company_id' => $this->company->id, 'environment' => $env, 'ecf_type' => $tipo,
        'range_from' => 1, 'range_to' => 100, 'next_number' => 1, 'is_active' => true,
        'expires_at' => now()->addYear()->endOfYear(),
    ]);

    $this->certificado = function (): void {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'PRUEBA BMIA', 'countryName' => 'DO'], $llave, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export(openssl_csr_sign($csr, null, $llave, 365, ['digest_alg' => 'sha256']), $p12, $llave, 'clave');
        app(CertificateVault::class)->store($this->company, $p12, 'clave');
    };

    $this->modo = fn (EmissionMode $m, Environment $env = Environment::Pruebas) => $this->ajustes
        ->forceFill(['emission_mode' => $m->value, 'environment' => $env])->save();
});

it('apagado: la factura es de la serie B y no se genera ningún e-CF', function (): void {
    ($this->serieB)();

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());

    expect($factura->ncf)->toBe('B0200000001')
        ->and(ElectronicInvoice::count())->toBe(0)
        ->and($factura->getAttributes()['electronic_invoice_id'] ?? null)->toBeNull();
});

it('en paralelo: la factura sigue siendo B y la acompaña un e-CF de prueba con la misma venta', function (): void {
    ($this->certificado)();
    ($this->serieB)();
    ($this->serieE)(EcfType::Consumo);
    ($this->modo)(EmissionMode::Sombra);

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    $ecf = ElectronicInvoice::sole();

    expect($factura->ncf)->toBe('B0200000001')
        ->and($factura->fresh()->electronic_invoice_id)->toBe($ecf->id)
        ->and($ecf->e_ncf)->toBe('E320000000001')
        ->and($ecf->source_type)->toBe('invoice')
        ->and($ecf->source_id)->toBe($factura->id)
        ->and((string) $ecf->total)->toBe('236.00')
        // Enviado después de confirmar la venta (proveedor de prueba: resumen aceptado).
        ->and($ecf->status)->toBe(EcfStatus::Aceptado);
});

it('en paralelo: si el e-CF falla, la factura B sale igual y queda el aviso', function (): void {
    ($this->certificado)();
    ($this->serieB)();
    ($this->modo)(EmissionMode::Sombra);
    // Sin secuencia E: el e-CF no puede numerarse.

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());

    expect($factura->ncf)->toBe('B0200000001')
        ->and(ElectronicInvoice::count())->toBe(0)
        ->and(SystemEvent::query()->where('type', 'ecf.shadow_failed')->count())->toBe(1);
});

it('real: el e-CF sustituye a la serie B y se envía a la DGII al confirmar la venta', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Consumo, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    $b = ($this->serieB)();

    Http::fake([
        'ecf.dgii.gov.do/ecf/autenticacion/api/autenticacion/semilla' => Http::response('<SemillaModel><valor>x</valor></SemillaModel>'),
        'ecf.dgii.gov.do/ecf/autenticacion/api/autenticacion/validarsemilla' => Http::response(['token' => 't', 'expira' => now()->addHour()->toIso8601String()]),
        'fc.dgii.gov.do/ecf/recepcionfc/api/recepcion/ecf' => Http::response(['codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => [], 'secuenciaUtilizada' => true]),
    ]);

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    $ecf = ElectronicInvoice::sole();

    expect($factura->ncf)->toBe('E320000000001')
        ->and($factura->fiscal_sequence_id)->toBeNull()
        ->and($factura->fresh()->electronic_invoice_id)->toBe($ecf->id)
        ->and($ecf->source_type)->toBe('invoice')
        ->and($ecf->status)->toBe(EcfStatus::Aceptado)
        // La serie B ni se tocó.
        ->and($b->fresh()->next_number)->toBe(1);

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://fc.dgii.gov.do/ecf/recepcionfc/'));
});

it('real: si el e-CF no se puede emitir, no hay factura ni se gasta número', function (): void {
    ($this->certificado)();
    $secuencia = ($this->serieE)(EcfType::CreditoFiscal, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $venta = ($this->venta)(['customerName' => null]);

    // Crédito fiscal sin razón social del comprador: lo exige el XSD.
    expect(fn () => app(InvoiceService::class)->issueForSale($venta, NcfType::CreditoFiscal, '101000007'))
        ->toThrow(InvoiceException::class, 'No se pudo emitir la factura electrónica');

    expect($secuencia->fresh()->next_number)->toBe(1)
        ->and(ElectronicInvoice::count())->toBe(0)
        ->and(\App\Modules\Billing\Models\Invoice::query()->where('sale_id', $venta->id)->count())->toBe(0);
});

it('real: un tipo que aún no sale de una venta se rechaza en vez de caer a la serie B', function (): void {
    ($this->certificado)();
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    ($this->serieB)(NcfType::NotaCredito);

    expect(fn () => app(InvoiceService::class)->issueForSale(($this->venta)(), NcfType::NotaCredito, '101000007'))
        ->toThrow(InvoiceException::class, 'todavía no se emite como e-CF');
});

it('el descuento global se reparte entre las líneas y la propina queda fuera del e-CF', function (): void {
    ($this->certificado)();
    ($this->serieB)();
    ($this->serieE)(EcfType::Consumo);
    ($this->modo)(EmissionMode::Sombra);

    $otro = Product::create(['sku' => 'P2', 'name' => 'Pan', 'cost' => '10', 'price' => '59']);
    app(StockService::class)->increase($otro, $this->warehouse, StockMovementType::Purchase, '100');

    $venta = ($this->venta)([
        'lines' => [
            new SaleLineData(productId: $this->producto->id, quantity: '2', unitPrice: '118'),
            new SaleLineData(productId: $otro->id, quantity: '1', unitPrice: '59', discount: '9'),
        ],
        'discountTotal' => '10.01',
        'tip' => '20',
    ]);

    app(InvoiceService::class)->issueForSale($venta);
    $ecf = ElectronicInvoice::sole();

    // 236 + 50 − 10.01 = 275.99 cobrados por la venta (sin la propina).
    expect((string) $ecf->total)->toBe(bcsub((string) $venta->total, '20', 2))
        ->and((string) $ecf->total)->toBe('275.99')
        ->and($ecf->status)->toBe(EcfStatus::Aceptado);
});

it('el modo se elige en la pantalla: «real» no en pruebas y nada sin certificado', function (): void {
    $duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@modo.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->post(route('panel.e-invoicing.mode.update'), ['emission_mode' => 'sombra'])
        ->assertSessionHasErrors('emission_mode');

    ($this->certificado)();

    $this->actingAs($duena)->post(route('panel.e-invoicing.mode.update'), ['emission_mode' => 'real'])
        ->assertSessionHasErrors('emission_mode');

    $this->actingAs($duena)->post(route('panel.e-invoicing.mode.update'), ['emission_mode' => 'sombra'])
        ->assertSessionHas('panel_ok');

    expect($this->ajustes->fresh()->emissionMode())->toBe(EmissionMode::Sombra);

    $this->actingAs($duena)->get(route('panel.e-invoicing'))->assertOk()->assertSee('Emisión en ventas y facturas');
});

it('un modo que no cuadra con el ambiente cuenta como apagado', function (): void {
    $this->ajustes->forceFill(['emission_mode' => 'real', 'environment' => Environment::Pruebas])->save();

    expect($this->ajustes->fresh()->emissionMode())->toBe(EmissionMode::Apagado);
});

/*
 * Fase 5b: notas de crédito (34) y débito (33). Un e-CF no se anula: se revierte con su nota.
 */

function ecfNotasDgiiFalsa(): void
{
    Http::fake([
        'ecf.dgii.gov.do/ecf/autenticacion/api/autenticacion/semilla' => Http::response('<SemillaModel><valor>x</valor></SemillaModel>'),
        'ecf.dgii.gov.do/ecf/autenticacion/api/autenticacion/validarsemilla' => Http::response(['token' => 't', 'expira' => now()->addHour()->toIso8601String()]),
        'fc.dgii.gov.do/ecf/recepcionfc/api/recepcion/ecf' => Http::response(['codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => [], 'secuenciaUtilizada' => true]),
        'ecf.dgii.gov.do/ecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'track-nota']),
    ]);
}

it('real: anular una factura e-CF emite su nota de crédito por el total y la referencia', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Consumo, Environment::Produccion);
    ($this->serieE)(EcfType::NotaCredito, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    ecfNotasDgiiFalsa();

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    app(InvoiceService::class)->cancel($factura, \App\Modules\Billing\Enums\CancellationReason::DevolucionProductos);

    $nota = ElectronicInvoice::query()->where('source_type', 'credit_note')->sole();
    $xml = Storage::disk('local')->get($nota->file('firmado')->path);

    expect($factura->fresh()->isCancelled())->toBeTrue()
        ->and($nota->e_ncf)->toBe('E340000000001')
        ->and($nota->source_id)->toBe($factura->id)
        ->and((string) $nota->total)->toBe('236.00')
        ->and($nota->status)->toBe(EcfStatus::Recibido)
        ->and($xml)->toContain('<NCFModificado>E320000000001</NCFModificado>')
        ->toContain('<CodigoModificacion>1</CodigoModificacion>');
});

it('real: sin poder emitir la nota de crédito, la factura no se anula', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Consumo, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    ecfNotasDgiiFalsa();

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());

    // Sin secuencia E34.
    expect(fn () => app(InvoiceService::class)->cancel($factura, \App\Modules\Billing\Enums\CancellationReason::DevolucionProductos))
        ->toThrow(InvoiceException::class, 'nota de crédito');

    expect($factura->fresh()->isCancelled())->toBeFalse();
});

it('en paralelo: anular la factura B genera la nota de prueba sin estorbar la anulación', function (): void {
    ($this->certificado)();
    ($this->serieB)();
    ($this->serieE)(EcfType::Consumo);
    ($this->serieE)(EcfType::NotaCredito);
    ($this->modo)(EmissionMode::Sombra);

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    app(InvoiceService::class)->cancel($factura, \App\Modules\Billing\Enums\CancellationReason::ErroresImpresion);

    expect($factura->fresh()->isCancelled())->toBeTrue()
        ->and(ElectronicInvoice::query()->where('source_type', 'credit_note')->value('e_ncf'))->toBe('E340000000001');
});

it('notas por importe desde la pantalla: crédito y débito, y el crédito no supera la factura', function (): void {
    ($this->certificado)();
    ($this->serieB)();
    ($this->serieE)(EcfType::Consumo);
    ($this->serieE)(EcfType::NotaCredito);
    ($this->serieE)(EcfType::NotaDebito);
    ($this->modo)(EmissionMode::Sombra);
    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());

    $duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@notas.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->post(route('panel.invoices.electronic-note', $factura), [
        'type' => 34, 'amount' => '200.00', 'indicator' => 1, 'reason' => 'Devolución parcial',
    ])->assertSessionHas('panel_ok');

    // 200 + 50 > 236: la DGII no lo admite [FMT campo 110 d)].
    $this->actingAs($duena)->post(route('panel.invoices.electronic-note', $factura), [
        'type' => 34, 'amount' => '50.00', 'indicator' => 1, 'reason' => 'Otra devolución',
    ])->assertSessionHas('panel_error', fn (string $m) => str_contains($m, 'superarían el total'));

    $this->actingAs($duena)->post(route('panel.invoices.electronic-note', $factura), [
        'type' => 33, 'amount' => '59.00', 'indicator' => 1, 'reason' => 'Cargo por envío',
    ])->assertSessionHas('panel_ok');

    expect(ElectronicInvoice::query()->where('source_type', 'credit_note')->count())->toBe(1)
        ->and(ElectronicInvoice::query()->where('source_type', 'debit_note')->value('e_ncf'))->toBe('E330000000001');

    $this->actingAs($duena)->get(route('panel.invoices'))->assertOk()->assertSee('Nota de crédito o débito')
        // La factura B lleva a la vista su e-CF de prueba y su estado.
        ->assertSee('e-CF prueba');
});

it('el cajero no emite notas electrónicas', function (): void {
    ($this->serieB)();
    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero', 'email' => 'cajero@notas.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.invoices.electronic-note', $factura), [
        'type' => 34, 'amount' => '10', 'indicator' => 1, 'reason' => 'x x x',
    ])->assertForbidden();
});

/*
 * Fase 5c: compras a proveedores informales — Compras (41) y Gastos menores (43), que emite la empresa.
 */

it('real: registrar una compra a un informal emite su e-CF 41 y ese es el NCF de la compra', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Compras, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    ecfNotasDgiiFalsa();

    $duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@compras.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($duena)->post(route('panel.purchase-invoices.store'), [
        'ecf_kind' => 'compras', 'ecf_is_service' => '1',
        'provider_name' => 'Juan Pérez', 'provider_tax_id' => '00100000009', 'provider_tax_id_kind' => '2',
        'goods_services_type' => '02', 'invoice_date' => now()->toDateString(),
        'amount' => '1000.00', 'itbis' => '0', 'isr_retenido' => '100.00', 'payment_method' => 'cash',
    ])->assertSessionHas('panel_ok', fn (string $m) => str_contains($m, 'E410000000001'));

    $compra = \App\Modules\Billing\Models\PurchaseInvoice::sole();
    $ecf = ElectronicInvoice::sole();
    $xml = Storage::disk('local')->get($ecf->file('firmado')->path);

    expect($compra->ncf)->toBe('E410000000001')
        ->and($compra->electronic_invoice_id)->toBe($ecf->id)
        ->and($ecf->source_type)->toBe('purchase_invoice')
        ->and($ecf->source_id)->toBe($compra->id)
        ->and($ecf->status)->toBe(EcfStatus::Recibido)
        // El proveedor informal va como «comprador» del 41.
        ->and($xml)->toContain('00100000009')->toContain('Juan Pérez');
});

it('en paralelo: la compra lleva su NCF en papel y además un e-CF 41 de prueba', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Compras);
    ($this->modo)(EmissionMode::Sombra);

    $compra = app(\App\Modules\Billing\Services\PurchaseInvoiceService::class)->create([
        'ecf_kind' => 'compras', 'ncf' => 'B1100000001',
        'provider_name' => 'Juan Pérez', 'provider_tax_id' => '00100000009', 'provider_tax_id_kind' => '2',
        'goods_services_type' => '09', 'invoice_date' => now()->toDateString(),
        'amount' => '500.00', 'itbis' => '0', 'payment_method' => 'cash',
    ], null, null);

    expect($compra->ncf)->toBe('B1100000001')
        ->and(ElectronicInvoice::sole()->e_ncf)->toBe('E410000000001')
        ->and($compra->fresh()->electronic_invoice_id)->toBe(ElectronicInvoice::sole()->id);
});

it('real: gastos menores con ITBIS se rechaza y la compra no se guarda', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::GastosMenores, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);

    expect(fn () => app(\App\Modules\Billing\Services\PurchaseInvoiceService::class)->create([
        'ecf_kind' => 'gastos_menores', 'provider_tax_id_kind' => '2',
        'goods_services_type' => '06', 'invoice_date' => now()->toDateString(),
        'amount' => '100.00', 'itbis' => '18.00', 'payment_method' => 'cash',
    ], null, null))->toThrow(InvoiceException::class);

    expect(\App\Modules\Billing\Models\PurchaseInvoice::count())->toBe(0)
        ->and(ElectronicInvoice::count())->toBe(0);
});

it('apagado: pedir el comprobante electrónico sin escribir el NCF se explica, no se inventa un número', function (): void {
    expect(fn () => app(\App\Modules\Billing\Services\PurchaseInvoiceService::class)->create([
        'ecf_kind' => 'compras', 'provider_tax_id_kind' => '2',
        'goods_services_type' => '09', 'invoice_date' => now()->toDateString(),
        'amount' => '100.00', 'payment_method' => 'cash',
    ], null, null))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(\App\Modules\Billing\Models\PurchaseInvoice::count())->toBe(0);
});

/*
 * Fase 6c: representación impresa con timbre (QR) [IT §18; DT pp.40–42].
 */

it('el timbre de un consumo bajo el umbral apunta a consultatimbrefc con los datos del XML firmado', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::Consumo, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    ecfNotasDgiiFalsa();

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());
    $ecf = ElectronicInvoice::sole();
    $t = app(\App\Modules\Billing\Contracts\ElectronicInvoicingHook::class)->printedRepresentation($factura);

    expect($t['url'])->toBe("https://fc.dgii.gov.do/ecf/consultatimbrefc?rncemisor=131000002&encf=E320000000001&montototal=236.00&codigoseguridad={$ecf->security_code}")
        ->and($t['tipo'])->toBe('Factura de Consumo Electrónica')
        ->and($t['codigo'])->toBe($ecf->security_code)
        ->and($t['fiscal'])->toBeTrue()
        ->and($t['vence'])->not->toBeNull()
        ->and(base64_decode(substr($t['qr'], strlen('data:image/svg+xml;base64,'))))->toContain('<svg');

    // El PDF A4 lleva el timbre.
    $html = view('invoices.pdf', ['invoice' => $factura->load('items'), 'company' => $this->company, 'logo' => null, 'timbre' => $t])->render();
    expect($html)->toContain('Código de seguridad')->toContain('E320000000001')->toContain('Factura de Consumo Electrónica');

    $duena = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña', 'email' => 'duena@pdf.test', 'password' => 'secret-password',
    ]), 'owner');
    $this->actingAs($duena)->get(route('panel.invoices.pdf', $factura))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('el timbre de un crédito fiscal lleva comprador, fechas y firma; en pruebas avisa que no tiene validez', function (): void {
    ($this->certificado)();
    ($this->serieE)(EcfType::CreditoFiscal, Environment::Produccion);
    ($this->modo)(EmissionMode::Real, Environment::Produccion);
    $this->ajustes->forceFill(['provider' => 'dgii'])->save();
    ecfNotasDgiiFalsa();

    $factura = app(InvoiceService::class)->issueForSale(($this->venta)(), NcfType::CreditoFiscal, '101000007');
    $t = app(\App\Modules\Billing\Contracts\ElectronicInvoicingHook::class)->printedRepresentation($factura);

    expect($t['url'])->toStartWith('https://ecf.dgii.gov.do/ecf/consultatimbre?rncemisor=131000002&rnccomprador=101000007&encf=E310000000001&fechaemision=')
        ->toContain('&montototal=236.00&fechafirma=')
        // El espacio de la fecha de firma va codificado como %20, como en el ejemplo oficial.
        ->toMatch('/fechafirma=\d{2}-\d{2}-\d{4}%20\d{2}:\d{2}:\d{2}&codigoseguridad=/');
});

it('una factura de la serie B no lleva timbre', function (): void {
    ($this->serieB)();
    $factura = app(InvoiceService::class)->issueForSale(($this->venta)());

    expect(app(\App\Modules\Billing\Contracts\ElectronicInvoicingHook::class)->printedRepresentation($factura))->toBeNull();
});

it('el QR intenta la versión 8 y, si la URL no cabe, usa la menor que la contenga', function (): void {
    $qr = app(\App\Modules\ElectronicInvoicing\Printing\QrCode::class);

    expect($qr->svg('https://fc.dgii.gov.do/ecf/consultatimbrefc?rncemisor=131000002&encf=E320000000001&montototal=236.00&codigoseguridad=abc123')['version'])->toBe(8)
        ->and($qr->svg(str_repeat('x', 230))['version'])->toBeGreaterThan(8);
});
