<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Forms Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Admin',
        'email' => 'admin@forms.test',
        'password' => 'secret-password',
    ]));
});

it('crea un producto con stock inicial', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.products.store'), [
            'sku' => 'NP-1', 'name' => 'Nuevo Prod', 'cost' => '10', 'price' => '20',
            'unit' => 'unidad', 'initial_stock' => '15',
        ])
        ->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    $product = Product::where('sku', 'NP-1')->firstOrFail();

    expect($product->name)->toBe('Nuevo Prod')
        ->and(Stock::where('product_id', $product->id)->firstOrFail()->quantity)->toBe('15.000');
});

it('valida el SKU requerido y no crea nada', function (): void {
    $this->actingAs($this->user)
        ->post(route('panel.products.store'), ['name' => 'Sin SKU'])
        ->assertSessionHasErrors('sku');

    app(CurrentCompany::class)->set($this->company->id);
    expect(Product::count())->toBe(0);
});

it('crea cliente, proveedor y empleado', function (): void {
    $this->actingAs($this->user)->post(route('panel.customers.store'), ['name' => 'Cliente Nuevo', 'email' => 'c@x.com'])->assertRedirect();
    $this->actingAs($this->user)->post(route('panel.suppliers.store'), ['name' => 'Proveedor Nuevo'])->assertRedirect();
    $this->actingAs($this->user)->post(route('panel.employees.store'), ['name' => 'Empleado Nuevo', 'position' => 'Cajero'])->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    expect(Customer::where('name', 'Cliente Nuevo')->exists())->toBeTrue()
        ->and(Supplier::where('name', 'Proveedor Nuevo')->exists())->toBeTrue()
        ->and(Employee::where('name', 'Empleado Nuevo')->exists())->toBeTrue();
});
