<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\CustomerDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'CRM Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@crm.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('crea un cliente con cédula', function (): void {
    $this->actingAs($this->owner)
        ->post(route('panel.customers.store'), ['name' => 'Juan Cédula', 'cedula' => '001-1234567-8'])
        ->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    expect(Customer::where('name', 'Juan Cédula')->firstOrFail()->cedula)->toBe('001-1234567-8');
});

it('el perfil del cliente muestra su cédula y documentos', function (): void {
    $customer = Customer::create(['name' => 'Ana', 'cedula' => '002-7654321-0']);

    $this->actingAs($this->owner)
        ->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('002-7654321-0')
        ->assertSee('Documentos');
});

it('sube un documento al perfil y se puede descargar', function (): void {
    $customer = Customer::create(['name' => 'Con Documento']);
    $file = UploadedFile::fake()->create('cedula.pdf', 120, 'application/pdf');

    $this->actingAs($this->owner)
        ->post(route('panel.customers.documents.store', $customer), ['file' => $file, 'name' => 'Cédula frontal'])
        ->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    $doc = CustomerDocument::where('customer_id', $customer->id)->firstOrFail();
    expect($doc->name)->toBe('Cédula frontal')->and($doc->mime)->toBe('application/pdf');

    $this->actingAs($this->owner)
        ->get(route('panel.customers.documents.show', [$customer, $doc]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('valida el tipo de archivo del documento', function (): void {
    $customer = Customer::create(['name' => 'X']);
    $bad = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    $this->actingAs($this->owner)
        ->post(route('panel.customers.documents.store', $customer), ['file' => $bad])
        ->assertSessionHasErrors('file');
});

it('no muestra el perfil de un cliente de otra empresa', function (): void {
    $customer = Customer::create(['name' => 'Propio']);

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena SRL'));
    $intruso = withRole(User::create([
        'company_id' => $otra->id, 'name' => 'Intruso',
        'email' => 'intruso@crm.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($intruso)->get(route('panel.customers.show', $customer))->assertNotFound();
});
