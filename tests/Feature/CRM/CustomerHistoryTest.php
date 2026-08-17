<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * La ficha del cliente vista desde dentro.
 *
 * Hasta ahora el PORTAL PÚBLICO enseñaba más que esta pantalla: el cliente veía sus facturas, sus
 * compras y sus entregas, y el dueño del negocio no veía ninguna de las tres. Los datos ya estaban
 * enlazados; solo faltaba pintarlos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Historial Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@historial.test', 'password' => 'secret-password',
    ]), 'owner');
});

function ventaDe(Customer $cliente, string $total, string $pagado, string $codigo = 'V-1'): Sale
{
    return Sale::create([
        'company_id' => $cliente->company_id,
        'customer_id' => $cliente->id,
        'code' => $codigo,
        'status' => 'completed',
        'warehouse_id' => Warehouse::firstOrFail()->id,
        'subtotal' => $total, 'tax' => '0.00', 'total' => $total,
        'paid' => $pagado, 'change' => '0.00',
        'payment_method' => 'cash', 'completed_at' => now(),
    ]);
}

it('la ficha enseña lo que el cliente compró', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana']);
    ventaDe($customer, '1500.00', '1500.00');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('Últimas compras')
        ->assertSee('1,500.00');
});

it('enseña cuánto le debe, que es lo fiado', function (): void {
    // El saldo de un colmado no sale de las facturas —esas no llevan saldo— sino de la diferencia
    // entre lo que suman sus ventas y lo que ha pagado.
    $customer = Customer::create(['name' => 'Don Fiado']);
    ventaDe($customer, '1000.00', '400.00');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('te debe')
        ->assertSee('600.00');
});

it('quien no debe nada no sale en rojo', function (): void {
    // Un cero en rojo en cada ficha desensibiliza la vista para cuando sí deba.
    $customer = Customer::create(['name' => 'Al día']);
    ventaDe($customer, '500.00', '500.00');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('no te debe nada')
        ->assertDontSee('data-tono="tone-rose"', false);
});

it('lo pagado de menos se marca como fiado en la lista', function (): void {
    $customer = Customer::create(['name' => 'Don Fiado']);
    ventaDe($customer, '1000.00', '400.00');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('fiado');
});

it('enseña sus entregas', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana']);
    Delivery::create([
        'company_id' => $this->company->id, 'customer_id' => $customer->id,
        'code' => 'E-001', 'status' => 'pending', 'address' => 'Calle Duarte 45',
    ]);

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('Entregas')
        ->assertSee('Calle Duarte 45');
});

it('no enseña lo de un cliente de otra empresa', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana']);
    ventaDe($customer, '1500.00', '1500.00');

    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Otra'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Customer::create(['name' => 'Cliente ajeno']);
    ventaDe($ajeno, '9999.00', '9999.00', 'V-AJENA');

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertDontSee('9,999.00');
});

it('sin el módulo de facturación no se pinta esa tarjeta', function (): void {
    // Una tabla vacía de un módulo que no se tiene se lee como un error.
    $this->company->update(['modules' => ['crm', 'sales']]);
    app(CurrentCompany::class)->forget();
    app(CurrentCompany::class)->set($this->company->id);

    $customer = Customer::create(['name' => 'Doña Ana']);

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertDontSee('NCF');
});

it('las notas se guardan al crear y se leen en la ficha', function (): void {
    /*
     * La columna llevaba desde el primer día en la base, era `fillable` y estaba en el DTO — pero no
     * tenía campo, ni regla de validación, ni se mostraba. No había forma de escribirla ni de leerla.
     */
    $this->actingAs($this->owner)->post(route('panel.customers.store'), [
        'name' => 'Doña Ana',
        'notes' => 'Paga los viernes. Atender por la puerta de atrás.',
    ])->assertRedirect();

    app(CurrentCompany::class)->set($this->company->id);
    $customer = Customer::where('name', 'Doña Ana')->firstOrFail();

    expect($customer->notes)->toBe('Paga los viernes. Atender por la puerta de atrás.');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('Paga los viernes');
});

it('los préstamos se anuncian aunque no haya ninguno', function (): void {
    // Una tarjeta que aparece y desaparece hace dudar de si el módulo funciona.
    $customer = Customer::create(['name' => 'Doña Ana']);

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))
        ->assertOk()
        ->assertSee('Préstamos del cliente')
        ->assertSee('no tiene préstamos');
});

it('la segunda visita no vuelve a preguntar por sus compras', function (): void {
    $customer = Customer::create(['name' => 'Doña Ana']);
    ventaDe($customer, '100.00', '100.00');

    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))->assertOk();

    // Con la caché caliente, la consulta de ventas ya no se repite.
    DB::enableQueryLog();
    $this->actingAs($this->owner)->get(route('panel.customers.show', $customer))->assertOk();
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($consultas->filter(fn (string $q): bool => str_contains($q, 'from "sales"')))->toBeEmpty();
});
