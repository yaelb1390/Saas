<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Contar la existencia y dejarla en lo contado.
 *
 * Es lo que de verdad hace un negocio: abre la nevera, cuenta veinticuatro y quiere que el sistema
 * diga veinticuatro. Lo que se vigila aquí es que esa comodidad NO se lleve por delante el kardex:
 * el saldo se mueve registrando la diferencia, nunca escribiendo el número encima.
 *
 * Sobrescribirlo dejaría el kardex diciendo una cosa y el saldo otra, y el día que no cuadre no
 * habría forma de saber quién lo cambió ni cuándo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado del Conteo'));
    $this->encargado = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Encargado',
        'email' => 'encargado@colmado.test', 'password' => 'secret-password',
    ]));
    app(CurrentCompany::class)->set($this->company->id);

    $this->almacen = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    /*
     * `track_stock` va explícito aunque la columna ya lo tenga por omisión.
     *
     * create() devuelve el objeto con lo que se le pasó, NO con los valores por defecto de la base:
     * sin ponerlo, el modelo en memoria trae null, el cast lo convierte en false y el servicio
     * rechaza el conteo de un producto que en la base sí lleva inventario. En el uso real no pasa
     * —el producto llega del enlace de la ruta, ya leído de la base— pero aquí engañaba.
     */
    $this->producto = Product::create([
        'sku' => 'AGUA', 'name' => 'Agua', 'cost' => '10', 'price' => '25', 'track_stock' => true,
    ]);
    app(StockService::class)->increase($this->producto, $this->almacen, StockMovementType::Purchase, '10');

    $this->conteo = app(StockCountService::class);
});

/**
 * La existencia actual, sin ceros de más.
 *
 * El nombre lleva apellido a propósito: los ayudantes de los tests son GLOBALES para toda la suite,
 * así que un «existencia()» a secas choca con el que ya tiene SaleBulkVoidTest y tumba la ejecución
 * entera con un «Cannot redeclare». Y lo peor: el fichero pasa cuando se ejecuta solo.
 */
function existenciaContada(int $productId): string
{
    $n = (string) (Stock::query()->where('product_id', $productId)->sum('quantity'));

    return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
}

it('deja la existencia en lo contado, no suma lo contado', function (): void {
    /*
     * La diferencia entre contar y dar entrada.
     *
     * Si contar SUMARA, decir «hay 15» cuando el sistema creía 10 dejaría 25, y el segundo conteo
     * del día dispararía la cifra. Contar es fijar, no añadir.
     */
    $this->conteo->ajustar($this->producto, '15');

    expect(existenciaContada($this->producto->id))->toBe('15');
});

it('lo que se registra es la DIFERENCIA, con su antes y su después', function (): void {
    // Es lo que hace que el kardex siga contando la verdad: quien mire el histórico ve un +5, no un
    // número que apareció de la nada.
    $this->conteo->ajustar($this->producto, '15');

    $movimiento = StockMovement::query()->latest('id')->firstOrFail();

    expect($movimiento->type)->toBe(StockMovementType::Adjustment)
        ->and((string) $movimiento->quantity)->toStartWith('5')
        ->and((string) $movimiento->quantity_before)->toStartWith('10')
        ->and((string) $movimiento->quantity_after)->toStartWith('15');
});

it('también sirve para corregir hacia ABAJO', function (): void {
    /*
     * Esto es lo que la entrada de mercancía no puede hacer, y por eso hacía falta.
     *
     * Cuando faltan tres —se rompieron, se regalaron, alguien se los llevó— no hay ninguna «entrada»
     * que registrar: hay que bajar la cifra, y hasta ahora no había por dónde.
     */
    $this->conteo->ajustar($this->producto, '7');

    expect(existenciaContada($this->producto->id))->toBe('7')
        ->and((string) StockMovement::query()->latest('id')->value('quantity'))->toStartWith('-3');
});

it('la nota explica de qué a qué se pasó, no solo el salto', function (): void {
    // Dentro de seis meses «+5» no dice nada; «de 10 a 15» se entiende sin abrir otra pantalla.
    $this->conteo->ajustar($this->producto, '15', 'Llegó una caja sin factura');

    $nota = (string) StockMovement::query()->latest('id')->value('notes');

    expect($nota)->toContain('de 10 a 15')
        ->and($nota)->toContain('Llegó una caja sin factura');
});

it('un producto sin control de existencias se rechaza, y se dice por qué', function (): void {
    /*
     * No se enciende el control por nuestra cuenta.
     *
     * Que un producto lleve inventario es una decisión del negocio —un servicio no se cuenta— y
     * cambiarla de refilón haría aparecer avisos de stock bajo de cosas que no están en ningún
     * estante.
     */
    $servicio = Product::create(['sku' => 'MANO', 'name' => 'Mano de obra', 'price' => '500', 'track_stock' => false]);

    expect(fn () => $this->conteo->ajustar($servicio, '5'))
        ->toThrow(DomainException::class, 'no lleva control de existencias');
});

it('contar lo mismo que ya había no deja un movimiento vacío', function (): void {
    // Un movimiento de cero ensucia el kardex sin aportar nada: quien lo lea mañana tendrá que
    // pararse a comprobar que efectivamente no pasó nada.
    $antes = StockMovement::query()->count();

    expect(fn () => $this->conteo->ajustar($this->producto, '10'))
        ->toThrow(DomainException::class, 'no hay nada que ajustar');

    expect(StockMovement::query()->count())->toBe($antes);
});

it('no se admite una cantidad negativa', function (): void {
    expect(fn () => $this->conteo->ajustar($this->producto, '-3'))
        ->toThrow(DomainException::class, 'no puede ser negativa');
});

it('exige el permiso de mover existencias: un empleado de mostrador no puede', function (): void {
    /*
     * Quien puede mover existencias puede tapar un faltante, así que es el mismo permiso que dar
     * entrada de mercancía, y el rol de mostrador no lo tiene.
     */
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Empleado',
        'email' => 'empleado@colmado.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero)
        ->post(route('panel.stock.count', $this->producto), ['counted' => '99'])
        ->assertForbidden();

    expect(existenciaContada($this->producto->id))->toBe('10');
});

it('no se puede contar el producto de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['sku' => 'SUYO', 'name' => 'Suyo', 'price' => '10']);
    app(CurrentCompany::class)->set($this->company->id);

    // El binding resuelve ya aislado por empresa: un id de otra devuelve 404 y no llega al servicio.
    $this->actingAs($this->encargado)
        ->post(route('panel.stock.count', $ajeno), ['counted' => '99'])
        ->assertNotFound();
});

it('desde la pantalla, contar deja la existencia y avisa', function (): void {
    $this->actingAs($this->encargado)
        ->post(route('panel.stock.count', $this->producto), ['counted' => '24', 'note' => 'Conteo del lunes'])
        ->assertRedirect();

    expect(existenciaContada($this->producto->id))->toBe('24')
        ->and(session('panel_ok'))->toContain('24');
});
