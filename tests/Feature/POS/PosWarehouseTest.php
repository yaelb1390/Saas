<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleVoidService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * De qué almacén sale lo que se cobra en el mostrador.
 *
 * EL FALLO QUE MOTIVA ESTO: el punto de venta descontaba SIEMPRE del almacén marcado por omisión,
 * escrito a fuego. La pantalla de «Entrada de mercancía» sí pregunta a qué almacén entra la
 * mercancía, así que el sistema dejaba RECIBIR cien piezas en la sucursal y luego no venderlas desde
 * ahí: el cobro las buscaba en el principal, no las encontraba, y la venta se caía por existencia
 * insuficiente.
 *
 * Con un solo almacén no se nota. Se nota el día que el negocio abre el segundo.
 */

uses(RefreshDatabase::class);

/** Una caja abierta contra el almacén que se le diga. */
function turnoEnAlmacen(int $companyId, User $usuario, ?int $warehouseId): CashSession
{
    $caja = CashRegister::query()->firstOrCreate(
        ['company_id' => $companyId, 'name' => 'Caja 1'],
        ['is_active' => true],
    );

    return CashSession::create([
        'company_id' => $companyId,
        'cash_register_id' => $caja->id,
        'warehouse_id' => $warehouseId,
        'user_id' => $usuario->id,
        'status' => CashSessionStatus::Open,
        'opening_amount' => '1000',
        'opened_at' => now(),
    ]);
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Dos Almacenes'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@almacenes.test', 'password' => 'secret-password',
    ]), 'owner');

    // El que viene de fábrica y el que abre el negocio cuando crece.
    $this->principal = Warehouse::query()->where('is_default', true)->firstOrFail();
    $this->sucursal = Warehouse::create([
        'company_id' => $this->company->id, 'name' => 'Sucursal 2', 'is_active' => true, 'is_default' => false,
    ]);

    $this->producto = Product::create(['sku' => 'TUB-1', 'name' => 'Tubo PVC 4', 'price' => '500', 'cost' => '300']);

    // TODA la existencia está en la sucursal. En el principal no hay ni una.
    Stock::create([
        'company_id' => $this->company->id, 'product_id' => $this->producto->id,
        'warehouse_id' => $this->sucursal->id, 'quantity' => '10',
    ]);
});

/** Lo que hay de un producto en un almacén concreto. */
function existenciaEn(int $productId, int $warehouseId): string
{
    return (string) (Stock::withoutGlobalScopes()
        ->where('product_id', $productId)
        ->where('warehouse_id', $warehouseId)
        ->value('quantity') ?? '0');
}

it('se vende del almacén de la caja, no del de por omisión', function (): void {
    /*
     * EL TEST QUE JUSTIFICA TODO EL CAMBIO.
     *
     * La existencia solo está en la sucursal. Antes, el cobro la buscaba en el principal y la venta
     * se caía —con la mercancía en el estante—.
     */
    turnoEnAlmacen($this->company->id, $this->cajero, $this->sucursal->id);

    $carrito = json_encode([['id' => $this->producto->id, 'qty' => 3]]);

    $this->actingAs($this->cajero)
        ->post(route('panel.pos.checkout'), ['cart' => $carrito, 'paid' => '2000'])
        ->assertRedirect();

    $venta = Sale::query()->latest('id')->first();

    expect($venta)->not->toBeNull()
        ->and($venta->warehouse_id)->toBe($this->sucursal->id)
        // Bajó de donde estaba…
        ->and(existenciaEn($this->producto->id, $this->sucursal->id))->toBe('7.000')
        // …y el principal ni se tocó: nunca tuvo nada.
        ->and(existenciaEn($this->producto->id, $this->principal->id))->toBe('0');
});

it('anular esa venta devuelve la mercancía al MISMO almacén', function (): void {
    /*
     * La otra mitad. Si la devolución fuera al de por omisión, cada venta anulada movería existencia
     * de un almacén a otro sin que nadie lo pidiera, y el inventario se iría separando de la realidad
     * poco a poco, que es la peor forma de romperse: sin ruido.
     */
    turnoEnAlmacen($this->company->id, $this->cajero, $this->sucursal->id);

    $this->actingAs($this->cajero)->post(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $this->producto->id, 'qty' => 4]]), 'paid' => '2000',
    ])->assertRedirect();

    expect(existenciaEn($this->producto->id, $this->sucursal->id))->toBe('6.000');

    app(SaleVoidService::class)->void(Sale::query()->latest('id')->first(), 'prueba');

    expect(existenciaEn($this->producto->id, $this->sucursal->id))->toBe('10.000')
        ->and(existenciaEn($this->producto->id, $this->principal->id))->toBe('0');
});

it('una caja abierta ANTES de la migración sigue cobrando, contra el de por omisión', function (): void {
    /*
     * Aquí las migraciones se aplican a mano y puede haber turnos abiertos en ese momento. Una sesión
     * sin almacén no puede dejar a nadie sin poder cobrar: cae al de por omisión, que es lo que hacía
     * ayer, y a nadie se le rompe la jornada a media mañana.
     */
    Stock::create([
        'company_id' => $this->company->id, 'product_id' => $this->producto->id,
        'warehouse_id' => $this->principal->id, 'quantity' => '5',
    ]);

    turnoEnAlmacen($this->company->id, $this->cajero, null);

    $this->actingAs($this->cajero)->post(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $this->producto->id, 'qty' => 2]]), 'paid' => '2000',
    ])->assertRedirect();

    expect(Sale::query()->latest('id')->first()->warehouse_id)->toBe($this->principal->id)
        ->and(existenciaEn($this->producto->id, $this->principal->id))->toBe('3.000')
        // Y la sucursal intacta.
        ->and(existenciaEn($this->producto->id, $this->sucursal->id))->toBe('10.000');
});

it('no se puede abrir caja contra el almacén de otra empresa', function (): void {
    /*
     * `exists` consulta la tabla directamente, sin pasar por el aislamiento por empresa. Sin acotarlo
     * a mano, un id ajeno pasaría la validación y una empresa acabaría descontando existencia de la
     * de al lado. Es el fallo de multiempresa clásico y no avisa: la venta sale bien.
     */
    $ajena = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    $suyo = Warehouse::withoutGlobalScopes()->where('company_id', $ajena->id)->firstOrFail();

    app(CurrentCompany::class)->set($this->company->id);

    $this->actingAs($this->cajero)
        ->post(route('panel.pos.open'), ['opening_amount' => '1000', 'warehouse_id' => $suyo->id])
        ->assertSessionHasErrors('warehouse_id');
});

it('al abrir sin elegir nada, el turno queda con un almacén puesto igualmente', function (): void {
    /*
     * Es lo que permite dejar de adivinarlo al cobrar. Si la sesión pudiera quedarse sin almacén,
     * habría que seguir teniendo la red del «por omisión» en todas partes, y con ella el fallo.
     */
    $this->actingAs($this->cajero)
        ->post(route('panel.pos.open'), ['opening_amount' => '1000'])
        ->assertRedirect();

    $sesion = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();

    expect($sesion)->not->toBeNull()
        ->and($sesion->warehouse_id)->not->toBeNull();
});

it('se puede cambiar el almacén a media jornada sin cerrar la caja', function (): void {
    /*
     * Quien se equivoca a las nueve de la mañana no debería tener que cuadrar el efectivo para
     * corregirlo. Lo ya cobrado NO se toca: esas ventas salieron del almacén que estaba puesto
     * entonces, y reescribirlas sería falsear el histórico.
     */
    turnoEnAlmacen($this->company->id, $this->cajero, $this->principal->id);

    $this->actingAs($this->cajero)
        ->post(route('panel.pos.warehouse'), ['warehouse_id' => $this->sucursal->id])
        ->assertRedirect();

    $sesion = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();

    expect($sesion->warehouse_id)->toBe($this->sucursal->id);

    // Y a partir de ahí se cobra del nuevo.
    $this->actingAs($this->cajero)->post(route('panel.pos.checkout'), [
        'cart' => json_encode([['id' => $this->producto->id, 'qty' => 1]]), 'paid' => '1000',
    ])->assertRedirect();

    expect(Sale::query()->latest('id')->first()->warehouse_id)->toBe($this->sucursal->id);
});
