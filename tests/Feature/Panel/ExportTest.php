<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Export Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'admin@export.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    Product::create(['sku' => 'AA-1', 'name' => 'Alfa', 'price' => '10']);
    Product::create(['sku' => 'BB-1', 'name' => 'Beta', 'price' => '20']);
});

it('exporta los productos a CSV', function (): void {
    $response = $this->actingAs($this->user)->get(route('panel.export.products'));

    $response->assertOk()->assertDownload('productos.csv');

    $content = $response->streamedContent();
    expect($content)->toContain('SKU')
        ->toContain('Alfa')
        ->toContain('Beta');
});

it('respeta el filtro de búsqueda al exportar', function (): void {
    $response = $this->actingAs($this->user)->get(route('panel.export.products', ['q' => 'Alfa']));

    $content = $response->streamedContent();
    expect($content)->toContain('Alfa')
        ->not->toContain('Beta');
});

it('exporta los productos a XLSX válido', function (): void {
    $response = $this->actingAs($this->user)->get(route('panel.export.products', ['format' => 'xlsx']));

    $response->assertOk()->assertDownload('productos.xlsx');
    expect($response->headers->get('content-type'))->toContain('spreadsheetml');

    // Un .xlsx real es un zip: firma "PK" y, al descomprimir la hoja, los datos exportados.
    $bytes = $response->baseResponse->getFile()->getContent();
    expect(substr($bytes, 0, 2))->toBe('PK');

    $tmp = (string) tempnam(sys_get_temp_dir(), 'xlsxtest');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive;
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheet)->toContain('SKU')->toContain('Alfa')->toContain('Beta');
});
