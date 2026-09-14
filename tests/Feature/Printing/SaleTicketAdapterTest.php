<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Printing\DTOs\SaveTemplateData;
use App\Modules\Printing\Services\DocumentRenderer;
use App\Modules\Printing\Services\TemplateService;
use App\Modules\Printing\Support\SaleTicketAdapter;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * LA INTEGRACIÓN DE REFERENCIA: una Venta real, convertida al contrato genérico y renderizada.
 *
 * Es lo que demuestra que el Centro de Impresión no es solo un editor de maquetas: con datos reales
 * de un módulo de negocio, produce un HTML de verdad Y los comandos ESC/POS de verdad.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Adapter Co'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();

    // track_stock=false: un producto de servicio, para no tener que dar entrada de mercancía antes
    // de vender. Aquí solo hace falta ALGUNA venta real, no probar el descuento de existencia.
    $this->producto = Product::create(['sku' => 'P1', 'name' => 'Refresco', 'cost' => '30', 'price' => '50', 'track_stock' => false]);

    $this->sale = app(SaleService::class)->complete(new CreateSaleData(
        warehouseId: $this->warehouse->id,
        lines: [new SaleLineData($this->producto->id, '2', '50')],
        paid: '100',
    ));
});

it('convierte una venta real al contrato generico con sus lineas y totales', function (): void {
    $data = SaleTicketAdapter::desde($this->sale);

    expect($data->reference)->toBe($this->sale->code)
        ->and($data->lines)->toHaveCount(1)
        ->and($data->lines[0]['description'])->toBe('Refresco')
        ->and($data->lines[0]['amount'])->toBe('100.00')
        ->and(collect($data->totals)->firstWhere('label', 'TOTAL')['value'])->toBe('100.00')
        ->and(collect($data->totals)->firstWhere('label', 'TOTAL')['emphasis'])->toBeTrue();
});

it('sin cliente, sale como Consumidor final', function (): void {
    $data = SaleTicketAdapter::desde($this->sale);

    expect(collect($data->meta)->firstWhere('label', 'Cliente')['value'])->toBe('Consumidor final');
});

it('el renderizador produce HTML real con el total de la venta, y ESC/POS con el corte', function (): void {
    $template = app(TemplateService::class)->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Ticket', 'paper_size' => '80mm',
    ]));

    $data = SaleTicketAdapter::desde($this->sale);
    $renderer = app(DocumentRenderer::class);

    $html = $renderer->renderHtml($template, $this->company, $data);
    expect($html['html'])->toContain('100.00')
        ->and($html['html'])->toContain($this->sale->code)
        ->and($html['ancho_mm'])->toBe(80)
        ->and($html['es_rollo'])->toBeTrue();

    $escposBinario = base64_decode($renderer->renderEscPos($template, $this->company, $data));
    // GS V (corte) va al final de cualquier ticket térmico: sin esto, el papel nunca se separa.
    expect($escposBinario)->toContain("\x1D\x56");
});

/*
 * EL PUNTO DONDE SE ENCHUFA DE VERDAD: la pantalla del recibo de venta, real, con el botón global.
 * No basta con que el servicio funcione aislado: esto prueba que la página que ya usa el cajero
 * cada día sigue respondiendo con el botón puesto.
 */
it('la pantalla del recibo de venta trae el boton de impresion global', function (): void {
    $this->withoutVite();
    $admin = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'D', 'email' => 'd@adapter.test', 'password' => 'secret-password',
    ]), 'owner');

    $html = $this->actingAs($admin)
        ->get(route('panel.sales.receipt', $this->sale))
        ->assertOk()->getContent();

    expect($html)->toContain('botonImprimir')
        ->and($html)->toContain('sale_ticket');
});
