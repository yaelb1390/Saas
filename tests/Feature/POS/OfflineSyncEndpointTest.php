<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * La puerta por la que entran las ventas cobradas sin internet.
 *
 * El servicio ya está probado aparte; lo que se fija aquí es la puerta: que pida permiso, que no
 * acepte cualquier cosa, y sobre todo QUE MANDAR EL MISMO LOTE DOS VECES no cobre dos veces. Un
 * terminal que recupera la línea reintenta sin saber si su envío anterior llegó, así que esa
 * repetición no es un caso raro: es el funcionamiento normal.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado HTTP'));
    $this->cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@colmado.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $almacen = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->producto = Product::create(['sku' => 'AGUA', 'name' => 'Agua', 'cost' => '10', 'price' => '25']);
    app(StockService::class)->increase($this->producto, $almacen, StockMovementType::Purchase, '50');

    $this->sesion = app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');
});

/**
 * Un lote tal como lo manda el terminal.
 *
 * @return array<string, mixed>
 */
function loteDelTerminal(?string $uuid = null): array
{
    return ['ventas' => [[
        'uuid' => $uuid ?? (string) Str::uuid(),
        'cash_session_id' => test()->sesion->id,
        'payment_method' => 'cash',
        'paid' => '100',
        'lines' => [[
            'product_id' => test()->producto->id,
            'quantity' => '2',
            'unit_price' => '25',
        ]],
    ]]];
}

it('mandar el mismo lote dos veces no cobra dos veces', function (): void {
    $lote = loteDelTerminal();

    $primera = $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $lote);
    $segunda = $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $lote);

    $primera->assertOk()->assertJsonPath('resultados.0.estado', 'registrada');
    $segunda->assertOk()->assertJsonPath('resultados.0.estado', 'ya_estaba');

    expect(Sale::query()->count())->toBe(1);
});

it('devuelve un resultado por venta, no un ok global', function (): void {
    /*
     * El terminal necesita saber CUÁL puede borrar de su cola. Con una respuesta global tendría que
     * elegir entre borrarlas todas —perdiendo la que no entró— o no borrar ninguna y reenviar
     * siempre lo mismo.
     */
    $lote = ['ventas' => [
        loteDelTerminal()['ventas'][0],
        loteDelTerminal()['ventas'][0],
        loteDelTerminal()['ventas'][0],
    ]];

    $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $lote)
        ->assertOk()
        ->assertJsonCount(3, 'resultados');
});

it('exige haber iniciado sesión', function (): void {
    // Sin esto, cualquiera podría inyectar ventas en la caja de un negocio ajeno.
    $this->postJson(route('panel.pos.offline.sync'), loteDelTerminal())->assertUnauthorized();
});

it('exige el permiso de operar el POS', function (): void {
    $mirón = User::create([
        'company_id' => $this->company->id, 'name' => 'Mirón',
        'email' => 'miron@colmado.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($mirón)->postJson(route('panel.pos.offline.sync'), loteDelTerminal())->assertForbidden();
});

it('rechaza un lote sin llave de idempotencia', function (): void {
    // Sin UUID no hay forma de saber si una venta ya entró, y reintentar dejaría de ser seguro.
    $lote = loteDelTerminal();
    unset($lote['ventas'][0]['uuid']);

    $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $lote)
        ->assertStatus(422)
        ->assertJsonValidationErrors('ventas.0.uuid');
});

it('rechaza un lote descomunal en vez de intentar tragárselo', function (): void {
    // Un terminal con la cola corrompida podría mandar cien mil ventas y tumbar la petición; el tope
    // hace que falle rápido y con un motivo, en vez de morir por tiempo de espera.
    $venta = loteDelTerminal()['ventas'][0];
    $enorme = ['ventas' => array_fill(0, 51, $venta)];

    $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $enorme)
        ->assertStatus(422)
        ->assertJsonValidationErrors('ventas');
});

it('la comprobación de sesión devuelve un token fresco', function (): void {
    /*
     * El terminal lo pide ANTES de subir nada, porque su pantalla puede llevar horas abierta desde
     * una copia guardada y su token CSRF ya no valdría: el envío daría 419 y el cajero vería fallar
     * la subida sin ninguna explicación.
     */
    $this->actingAs($this->cajero)->getJson(route('panel.pos.offline.estado'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure(['token']);
});

it('el cobro normal también reconoce una venta ya registrada', function (): void {
    /*
     * Misma llave, camino con conexión. Pasa cuando la respuesta se pierde pero la petición llegó y
     * el terminal reintenta: sin esta comprobación el cliente saldría cobrado dos veces.
     */
    $uuid = (string) Str::uuid();
    $lote = loteDelTerminal($uuid);

    $this->actingAs($this->cajero)->postJson(route('panel.pos.offline.sync'), $lote)->assertOk();

    $this->actingAs($this->cajero)->postJson(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $this->producto->id, 'qty' => 2]]),
        'paid' => '100',
        'client_uuid' => $uuid,
    ])->assertOk()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'ya estaba'));

    expect(Sale::query()->count())->toBe(1);
});
