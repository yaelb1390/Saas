<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Printing\DTOs\SaveTemplateData;
use App\Modules\Printing\Models\PrintTemplate;
use App\Modules\Printing\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * PLANTILLAS: el diseño de cada tipo de documento.
 *
 * LA REGLA QUE IMPORTA AQUÍ: una sola plantilla `is_default` POR TIPO de documento. La factura A4 y
 * el ticket de venta no compiten por ser "la" default —son documentos distintos—, pero DOS facturas
 * A4 sí compiten entre ellas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Plantillas Co'));
    app(CurrentCompany::class)->set($this->company->id);
    $this->svc = app(TemplateService::class);
});

it('el layout de fabrica trae RNC y NCF encendidos solo para facturas', function (): void {
    expect($this->svc->layoutPorDefecto('sale_ticket')['show_rnc'])->toBeFalse()
        ->and($this->svc->layoutPorDefecto('invoice_a4')['show_rnc'])->toBeTrue()
        ->and($this->svc->layoutPorDefecto('invoice_a4')['show_ncf'])->toBeTrue();
});

it('guardar completa el layout enviado sobre el de fabrica: las claves que faltan no rompen nada', function (): void {
    $template = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Mi ticket', 'paper_size' => '80mm',
        'layout' => ['footer_text' => 'Gracias!'], // Solo un campo, el resto debe rellenarse.
    ]));

    expect($template->layout['footer_text'])->toBe('Gracias!')
        ->and($template->layout['show_company_name'])->toBeTrue() // Vino del layout de fábrica.
        ->and($template->layout['logo']['size'])->toBe('md');
});

it('marcar una plantilla como default le quita esa marca a las demas del MISMO tipo', function (): void {
    $primera = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'A', 'paper_size' => '80mm', 'is_default' => true,
    ]));
    $segunda = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'B', 'paper_size' => '80mm', 'is_default' => true,
    ]));

    expect($primera->fresh()->is_default)->toBeFalse()
        ->and($segunda->fresh()->is_default)->toBeTrue();
});

it('marcar default en un tipo distinto no toca la default del otro tipo', function (): void {
    $ticket = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Ticket', 'paper_size' => '80mm', 'is_default' => true,
    ]));
    $factura = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'invoice_a4', 'name' => 'Factura', 'paper_size' => 'a4', 'is_default' => true,
    ]));

    expect($ticket->fresh()->is_default)->toBeTrue()
        ->and($factura->fresh()->is_default)->toBeTrue();
});

it('editar una plantilla existente actualiza en el sitio, no crea una nueva', function (): void {
    $template = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Original', 'paper_size' => '80mm',
    ]));

    $this->svc->guardar($template, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Renombrada', 'paper_size' => '80mm',
    ]));

    expect(PrintTemplate::count())->toBe(1)
        ->and($template->fresh()->name)->toBe('Renombrada');
});

it('predeterminadaPara encuentra la que esta marcada de ese tipo', function (): void {
    $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'A', 'paper_size' => '80mm', 'is_default' => false,
    ]));
    $marcada = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'B', 'paper_size' => '80mm', 'is_default' => true,
    ]));

    expect($this->svc->predeterminadaPara('sale_ticket')->id)->toBe($marcada->id)
        ->and($this->svc->predeterminadaPara('invoice_a4'))->toBeNull();
});

/*
 * AISLAMIENTO. Sin esto, una empresa podría marcar «predeterminada» y desmarcar sin querer la de
 * otra empresa con el mismo tipo de documento.
 */
it('no toca la plantilla default de otra empresa', function (): void {
    $miaDefault = $this->svc->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Mía', 'paper_size' => '80mm', 'is_default' => true,
    ]));

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    app(TemplateService::class)->guardar(null, SaveTemplateData::fromArray([
        'document_type' => 'sale_ticket', 'name' => 'Ajena', 'paper_size' => '80mm', 'is_default' => true,
    ]));

    app(CurrentCompany::class)->set($this->company->id);
    expect($miaDefault->fresh()->is_default)->toBeTrue();
});
