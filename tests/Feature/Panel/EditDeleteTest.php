<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'CRUD Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Admin',
        'email' => 'admin@crud.test',
        'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);
});

it('actualiza un producto', function (): void {
    $product = Product::create(['sku' => 'A1', 'name' => 'Viejo', 'cost' => '5', 'price' => '10']);

    $this->actingAs($this->user)
        ->put(route('panel.products.update', $product), ['sku' => 'A1', 'name' => 'Nuevo Nombre', 'price' => '20'])
        ->assertRedirect();

    expect($product->refresh()->name)->toBe('Nuevo Nombre');
});

it('elimina (soft delete) un producto', function (): void {
    $product = Product::create(['sku' => 'A2', 'name' => 'Borrar', 'price' => '1']);

    $this->actingAs($this->user)
        ->delete(route('panel.products.destroy', $product))
        ->assertRedirect();

    expect(Product::whereKey($product->id)->exists())->toBeFalse()
        ->and(Product::withoutGlobalScopes()->whereKey($product->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

it('actualiza y elimina un cliente', function (): void {
    $customer = Customer::create(['name' => 'Cliente Viejo']);

    $this->actingAs($this->user)
        ->put(route('panel.customers.update', $customer), ['name' => 'Cliente Editado'])
        ->assertRedirect();
    expect($customer->refresh()->name)->toBe('Cliente Editado');

    $this->actingAs($this->user)
        ->delete(route('panel.customers.destroy', $customer))
        ->assertRedirect();
    expect(Customer::whereKey($customer->id)->exists())->toBeFalse();
});

it('actualiza un proveedor', function (): void {
    $supplier = Supplier::create(['name' => 'Proveedor Viejo', 'is_active' => true]);

    $this->actingAs($this->user)
        ->put(route('panel.suppliers.update', $supplier), ['name' => 'Proveedor Nuevo', 'email' => 'p@x.test'])
        ->assertRedirect();

    expect($supplier->refresh()->name)->toBe('Proveedor Nuevo')
        ->and($supplier->email)->toBe('p@x.test');
});

it('actualiza un empleado', function (): void {
    $employee = Employee::create(['name' => 'Empleado Viejo', 'position' => 'Cajero', 'is_active' => true]);

    $this->actingAs($this->user)
        ->put(route('panel.employees.update', $employee), ['name' => 'Empleado Nuevo', 'position' => 'Gerente', 'salary' => '30000'])
        ->assertRedirect();

    expect($employee->refresh()->name)->toBe('Empleado Nuevo')
        ->and($employee->position)->toBe('Gerente')
        ->and((float) $employee->salary)->toBe(30000.0);
});

it('no permite editar un proveedor de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra Co'));
    app(CurrentCompany::class)->set($other->id);
    $foreign = Supplier::create(['name' => 'Ajeno', 'is_active' => true]);

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->put(route('panel.suppliers.update', $foreign->id), ['name' => 'Hackeado'])
        ->assertNotFound();

    expect(Supplier::withoutGlobalScopes()->whereKey($foreign->id)->value('name'))->toBe('Ajeno');
});

it('no permite eliminar un registro de otra empresa', function (): void {
    $other = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ajena'));
    app(CurrentCompany::class)->set($other->id);
    $foreignProduct = Product::create(['sku' => 'X', 'name' => 'Ajeno', 'price' => '1']);

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->user)
        ->delete(route('panel.products.destroy', $foreignProduct->id))
        ->assertNotFound();

    expect(Product::withoutGlobalScopes()->whereKey($foreignProduct->id)->whereNull('deleted_at')->exists())->toBeTrue();
});
