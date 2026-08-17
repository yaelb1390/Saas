<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\CRM\Models\Opportunity;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Archivar y eliminar dejan de ser el mismo botón.
 *
 * Antes había uno solo que hacía un borrado lógico, y como el cliente usa `SoftDeletes`, cada
 * `belongsTo(Customer::class)` de Ventas, Facturación, Entregas, WhatsApp y Préstamos pasaba a
 * devolver NULL con la clave ajena apuntando a una fila viva. La ficha de un préstamo de ese cliente
 * reventaba al pedir su nombre. Y el diálogo describía otra cosa: «se archiva y deja de aparecer al
 * vender», que es literalmente lo que significa `is_active`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Archivo Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@archivo.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------------------------- Archivar

it('archivar lo saca del listado sin borrar nada', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana']);

    $this->actingAs($this->owner)
        ->post(route('panel.customers.toggle', $customer))
        ->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);

    expect(Customer::whereKey($customer->id)->exists())->toBeTrue()
        ->and(Customer::whereKey($customer->id)->first()->is_active)->toBeFalse();

    // Y deja de salir en el listado, que es lo que se prometía.
    $this->actingAs($this->owner)->get(route('panel.customers'))
        ->assertOk()
        ->assertDontSee('Doña Ana');
});

it('un cliente archivado sigue apareciendo si se piden los archivados', function (): void {
    // Archivar solo significa algo si se puede ver lo archivado y traerlo de vuelta.
    $customer = Customer::create(['name' => 'Doña Ana', 'is_active' => false]);

    $this->actingAs($this->owner)->get(route('panel.customers', ['estado' => 'archivados']))
        ->assertOk()
        ->assertSee('Doña Ana');
});

it('archivar se puede deshacer', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana', 'is_active' => false]);

    $this->actingAs($this->owner)->post(route('panel.customers.toggle', $customer));

    app(CurrentCompany::class)->set($this->company->id);
    expect(Customer::whereKey($customer->id)->first()->is_active)->toBeTrue();
});

// ------------------------------------------------------------------------------------- Eliminar

it('no se puede eliminar a quien tiene una venta, y se dice por qué', function (): void {
    /*
     * Es el fallo que esto cierra: al borrarlo en blando, `Sale::customer()` devolvía null y
     * cualquier pantalla que pintara su nombre se caía.
     */
    $customer = Customer::create(['name' => 'Juan']);
    Sale::create([
        'company_id' => $this->company->id, 'customer_id' => $customer->id,
        'code' => 'V-100', 'status' => 'completed', 'warehouse_id' => Warehouse::firstOrFail()->id,
        'subtotal' => '100.00', 'tax' => '0.00', 'total' => '100.00', 'paid' => '100.00',
        'change' => '0.00', 'payment_method' => 'cash', 'completed_at' => now(),
    ]);

    $this->actingAs($this->owner)
        ->delete(route('panel.customers.destroy', $customer))
        ->assertSessionHas('panel_error');

    app(CurrentCompany::class)->set($this->company->id);
    expect(Customer::whereKey($customer->id)->exists())->toBeTrue();
});

it('el motivo dice cuántas cosas lo atan', function (): void {
    // «No se puede» a secas deja al dueño sin saber qué hacer. Con la cuenta, sabe si le importa.
    $customer = Customer::create(['name' => 'Juan']);

    foreach ([1, 2] as $i) {
        Sale::create([
            'company_id' => $this->company->id, 'customer_id' => $customer->id,
            'code' => 'V-20'.$i, 'status' => 'completed', 'warehouse_id' => Warehouse::firstOrFail()->id,
            'subtotal' => '50.00', 'tax' => '0.00', 'total' => '50.00', 'paid' => '50.00',
            'change' => '0.00', 'payment_method' => 'cash', 'completed_at' => now(),
        ]);
    }

    $this->actingAs($this->owner)->delete(route('panel.customers.destroy', $customer));

    expect(session('panel_error'))->toContain('2 ventas')
        ->and(session('panel_error'))->toContain('archivarlo');
});

it('a quien no tiene nada sí se le puede eliminar', function (): void {
    // Es el único caso que lo merece: la ficha creada por error hace cinco minutos.
    $customer = Customer::create(['name' => 'Se creó por error']);

    $this->actingAs($this->owner)
        ->delete(route('panel.customers.destroy', $customer))
        ->assertRedirect(route('panel.customers'))
        ->assertSessionHas('panel_ok');

    app(CurrentCompany::class)->set($this->company->id);
    expect(Customer::whereKey($customer->id)->exists())->toBeFalse();
});

it('una oportunidad abierta también lo ata', function (): void {
    $customer = Customer::create(['name' => 'Juan']);
    $pipeline = Pipeline::where('is_default', true)->firstOrFail();

    Opportunity::create([
        'company_id' => $this->company->id,
        'customer_id' => $customer->id,
        'pipeline_id' => $pipeline->id,
        'stage_id' => $pipeline->stages()->orderBy('position')->first()->id,
        'title' => 'Cotización',
    ]);

    $this->actingAs($this->owner)
        ->delete(route('panel.customers.destroy', $customer))
        ->assertSessionHas('panel_error');
});

it('un cajero no puede ni archivar ni eliminar', function (): void {
    $customer = Customer::create(['name' => 'Juan']);

    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@archivo.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)->post(route('panel.customers.toggle', $customer))->assertForbidden();
    $this->actingAs($cajero)->delete(route('panel.customers.destroy', $customer))->assertForbidden();
});
