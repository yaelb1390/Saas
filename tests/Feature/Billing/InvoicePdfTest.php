<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El PDF de una factura ya emitida.
 *
 * No existía antes de este cambio: la pantalla de Facturación solo tenía la tabla de comprobantes.
 * Lo que importa aquí es que el papel dice de quién viene, lleva el NCF real (no un número que se
 * inventa aparte) y que una empresa no puede pedir el PDF de la factura de otra cambiando el id.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería El Progreso'));
    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@factura.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'P1', 'name' => 'Cemento', 'cost' => '10', 'price' => '100']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '100');

    FiscalSequence::create([
        'type' => NcfType::Consumo, 'next_number' => 1, 'range_from' => 1, 'range_to' => 1000,
        'number_length' => 8, 'is_active' => true,
    ]);

    $sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData(productId: $this->product->id, quantity: '2', unitPrice: '100')],
        paid: '200',
    ));

    $this->invoice = app(InvoiceService::class)->issueForSale($sale, NcfType::Consumo);
});

it('el PDF de la factura se genera con el NCF real', function (): void {
    $respuesta = $this->actingAs($this->owner)->get(route('panel.invoices.pdf', $this->invoice));

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF');
});

it('el papel lleva el NCF, el número interno y el nombre del negocio', function (): void {
    $html = view('invoices.pdf', [
        'invoice' => $this->invoice->fresh()->load('items', 'customer', 'sale', 'user'),
        'company' => $this->company,
        'logo' => null,
    ])->render();

    expect($html)->toContain('FACTURA')
        ->and($html)->toContain($this->invoice->ncf)
        ->and($html)->toContain($this->invoice->numeroInterno())
        ->and($html)->toContain($this->company->name)
        // Una factura SÍ es un comprobante fiscal: no lleva el aviso que sí lleva la cotización.
        ->and($html)->not->toContain('no un comprobante fiscal');
});

it('una empresa no puede pedir el PDF de la factura de otra', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    $ajeno = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Otro dueño',
        'email' => 'otro@factura.test', 'password' => 'secret-password',
    ]));

    // El scope de empresa (CompanyScope) hace que la factura de la primera empresa simplemente no
    // exista para la segunda: el binding implícito de ruta la busca y no la encuentra.
    $this->actingAs($ajeno)->get(route('panel.invoices.pdf', $this->invoice))->assertNotFound();
});

it('con muchas líneas no se pierde ni una: pagina, no recorta', function (): void {
    // 30 líneas fuerza la densidad "ultra" (ver DocumentDensity) y, aun así, TODAS deben aparecer.
    // La descripción de una línea de factura es el NOMBRE DEL PRODUCTO (InvoiceService la copia de
    // ahí), así que hacen falta 30 productos distintos para poder comprobar que ninguno se pierde.
    $lineas = [];
    for ($i = 1; $i <= 30; $i++) {
        $producto = Product::create(['sku' => "MULTI-{$i}", 'name' => "Producto número {$i}", 'price' => '100']);
        app(StockService::class)->increase($producto, $this->warehouse, StockMovementType::Purchase, '10');
        $lineas[] = new SaleLineData(productId: $producto->id, quantity: '1', unitPrice: '100');
    }

    $ventaGrande = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id, lines: $lineas, paid: '3000',
    ));
    $facturaGrande = app(InvoiceService::class)->issueForSale($ventaGrande, NcfType::Consumo);

    $html = view('invoices.pdf', [
        'invoice' => $facturaGrande->load('items', 'customer', 'sale', 'user'),
        'company' => $this->company,
        'logo' => null,
    ])->render();

    expect($html)->toContain('data-densidad="ultra"');

    foreach ([1, 15, 30] as $n) {
        expect($html)->toContain("Producto número {$n}");
    }
});
