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
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Search Co'));
    $this->user = withRole(User::create([
        'company_id' => $this->company->id,
        'name' => 'Admin',
        'email' => 'admin@search.test',
        'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    Product::create(['sku' => 'LP-1', 'name' => 'Laptop HP', 'price' => '1000']);
    Product::create(['sku' => 'MO-1', 'name' => 'Mouse Logitech', 'price' => '20']);
});

it('filtra productos por nombre (insensible a mayúsculas)', function (): void {
    $this->actingAs($this->user)
        ->get(route('panel.products', ['q' => 'laptop']))
        ->assertOk()
        ->assertSee('Laptop HP')
        ->assertDontSee('Mouse Logitech');
});

it('filtra productos por SKU', function (): void {
    $this->actingAs($this->user)
        ->get(route('panel.products', ['q' => 'MO-1']))
        ->assertOk()
        ->assertSee('Mouse Logitech')
        ->assertDontSee('Laptop HP');
});
