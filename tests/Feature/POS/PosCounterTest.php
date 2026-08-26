<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * El mostrador que enseña el artículo entero.
 *
 * Antes los resultados eran tarjetas con foto y cuatro datos. Sirve en un colmado, donde
 * «Coca-Cola 2L» es inconfundible; no sirve en una ferretería, donde hay tres bombas de agua que
 * solo se distinguen por marca, aplicación y estante, y el dependiente acaba yendo al almacén a
 * mirar —o vendiendo la pieza equivocada—.
 */

uses(RefreshDatabase::class);

/**
 * El mostrador solo se pinta con la caja abierta: sin ella la pantalla enseña «Abrir caja» y ni
 * tabla ni ficha existen en el HTML.
 */
function conCajaAbierta(int $companyId, User $usuario): void
{
    $caja = CashRegister::query()->firstOrCreate(
        ['company_id' => $companyId, 'name' => 'Caja 1'],
        ['is_active' => true],
    );

    CashSession::create([
        'company_id' => $companyId,
        'cash_register_id' => $caja->id,
        'user_id' => $usuario->id,
        'status' => 'open',
        'opening_amount' => '1000',
        'opened_at' => now(),
    ]);
}

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Mostrador Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->pieza = Product::create([
        'sku' => 'PRB-4471', 'name' => 'Bomba de agua', 'price' => '2450', 'cost' => '1000',
        'part_number' => 'GMB-125-1420', 'brand' => 'GMB',
        'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla',
        'year_from' => 2003, 'year_to' => 2008,
        'location' => 'Pasillo 3 / Estante B', 'unit' => 'unidad',
        'description' => 'Incluye empaque. No incluye correa.',
    ]);
});

// ------------------------------------------------------------------ Lo que viaja a la pantalla

it('la búsqueda trae todo lo que identifica la pieza, no solo nombre y precio', function (): void {
    /*
     * El dato YA existía en el presenter y la pantalla lo tiraba. Este test fija que sigue viajando:
     * sin él, cualquiera puede «limpiar» el presenter quitando campos que parecen no usarse y dejar
     * la tabla llena de guiones sin que nada falle.
     */
    $fila = app(ProductLookupPresenter::class)->search('bomba')[0];

    expect($fila['part_number'])->toBe('GMB-125-1420')
        ->and($fila['brand'])->toBe('GMB')
        ->and($fila['vehicle'])->toBe('Toyota Corolla 2003-2008')
        ->and($fila['location'])->toBe('Pasillo 3 / Estante B')
        ->and($fila['unit'])->toBe('unidad')
        ->and($fila['description'])->toBe('Incluye empaque. No incluye correa.');
});

it('dice en qué almacén está la existencia, no solo cuánta hay', function (): void {
    /*
     * Un total de «12» no le sirve al dependiente si ocho están en la sucursal del otro lado de la
     * ciudad: le diría que sí a un cliente y luego no tendría qué entregarle.
     */
    $otro = Warehouse::create(['company_id' => $this->company->id, 'name' => 'Sucursal 2']);
    $principal = Warehouse::query()->where('id', '!=', $otro->id)->firstOrFail();

    Stock::create(['company_id' => $this->company->id, 'product_id' => $this->pieza->id, 'warehouse_id' => $principal->id, 'quantity' => '8']);
    Stock::create(['company_id' => $this->company->id, 'product_id' => $this->pieza->id, 'warehouse_id' => $otro->id, 'quantity' => '4']);

    $fila = app(ProductLookupPresenter::class)->search('bomba')[0];

    expect($fila['stock'])->toBe('12.000')
        ->and($fila['stock_por_almacen'])->toHaveCount(2);

    $nombres = array_column($fila['stock_por_almacen'], 'almacen');
    expect($nombres)->toContain('Sucursal 2');
});

it('el almacén que está a cero no se enseña', function (): void {
    // «Sucursal 2: 0» es ruido, y con muchos almacenes tapa las dos líneas que importan.
    $otro = Warehouse::create(['company_id' => $this->company->id, 'name' => 'Sucursal 2']);
    $principal = Warehouse::query()->where('id', '!=', $otro->id)->firstOrFail();

    Stock::create(['company_id' => $this->company->id, 'product_id' => $this->pieza->id, 'warehouse_id' => $principal->id, 'quantity' => '5']);
    Stock::create(['company_id' => $this->company->id, 'product_id' => $this->pieza->id, 'warehouse_id' => $otro->id, 'quantity' => '0']);

    $fila = app(ProductLookupPresenter::class)->search('bomba')[0];

    expect($fila['stock_por_almacen'])->toHaveCount(1)
        ->and($fila['stock_por_almacen'][0]['almacen'])->not->toBe('Sucursal 2');
});

it('la copia para trabajar sin conexión NO carga el desglose por almacén', function (): void {
    /*
     * Esa copia guarda hasta dos mil artículos en el navegador para poder cobrar sin línea. Meterle a
     * cada uno su lista de almacenes la engorda sin servir de nada: sin conexión nadie está mirando
     * en qué estante hay, está intentando cobrar.
     */
    $fila = app(ProductLookupPresenter::class)->catalog(null, 10)['results'][0];

    expect($fila['stock_por_almacen'])->toBeNull()
        // Pero la unidad sí va: es un texto corto y sin ella «12» no dice si son piezas o libras.
        ->and($fila['unit'])->toBe('unidad');
});

// ------------------------------------------------------------------ El costo sigue tapado

it('el COSTO no viaja a quien solo cobra', function (): void {
    /*
     * Lo pediste explícitamente al decidir el diseño, y aquí es donde se comprueba que traer «toda la
     * información» no se llevó por delante esa línea. Un cajero que ve el costo sabe el margen del
     * negocio, y eso sale por la puerta con él el día que se va.
     */
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@mostrador.test', 'password' => 'secret-password',
    ]), 'staff');

    $this->actingAs($cajero);

    $fila = app(ProductLookupPresenter::class)->search('bomba')[0];

    expect($fila['cost'])->toBeNull()
        // Y el de venta sí, o no se podría ni pintar la lista.
        ->and($fila['price'])->toBe('2450.00');
});

it('el costo SÍ viaja a quien da entrada de mercancía', function (): void {
    // La otra cara: si no llegara, la pantalla de entradas no podría avisar de que el costo cambió.
    $encargado = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Encargada',
        'email' => 'encargada@mostrador.test', 'password' => 'secret-password',
    ]), 'admin');

    $this->actingAs($encargado);

    expect(app(ProductLookupPresenter::class)->search('bomba')[0]['cost'])->toBe('1000.00');
});

// ------------------------------------------------------------------ Las mayúsculas

it('la búsqueda no distingue mayúsculas EN LA CONSULTA, no solo en SQLite', function (): void {
    /*
     * ESTE TEST MIRA EL SQL, y no es por gusto.
     *
     * Los tests corren sobre SQLite, donde `LIKE` ya ignora las mayúsculas por su cuenta. En
     * PostgreSQL —que es la base de este proyecto— NO las ignora, así que un test de comportamiento
     * («buscar bomba encuentra Bomba») pasa en SQLite tanto con el fallo como sin él, y no protege
     * nada. De hecho ya existía uno así y el fallo llegó a producción igualmente: quien escribía
     * «bomba» en el mostrador no encontraba nada y «Bomba» encontraba tres.
     *
     * Lo único que distingue lo correcto de lo roto en las dos bases es que la consulta baje el
     * texto a minúsculas, y eso es lo que se comprueba.
     */
    $sql = '';
    DB::listen(function ($consulta) use (&$sql): void {
        if (str_contains($consulta->sql, 'products')) {
            $sql .= $consulta->sql;
        }
    });

    app(ProductRepositoryInterface::class)->search('bomba');

    expect($sql)->toContain('lower(name) like')
        ->and($sql)->toContain('lower(part_number) like')
        ->and($sql)->toContain('lower(brand) like');
});

it('el comodín que escribe el cliente es texto, no un comodín', function (): void {
    /*
     * Quien busca «%» quiere los artículos que llevan un «%» en el nombre, no el catálogo entero.
     *
     * Sin escapar, ese «%» se colaría como comodín en el LIKE y devolvería TODO —incluida la bomba
     * de agua del beforeEach—, que es como una búsqueda deja de servir para buscar.
     */
    Product::create(['sku' => 'X-1', 'name' => 'Alcohol 70%', 'price' => '80']);
    Product::create(['sku' => 'X-2', 'name' => 'Alcohol isopropílico', 'price' => '90']);

    $presenter = app(ProductLookupPresenter::class);

    expect($presenter->search('70%'))->toHaveCount(1)
        ->and($presenter->search('alcohol'))->toHaveCount(2);

    $conPorciento = $presenter->search('%');

    expect($conPorciento)->toHaveCount(1)
        ->and($conPorciento[0]['name'])->toBe('Alcohol 70%');
});

// ------------------------------------------------------------------ Sin N+1

it('buscar veinte artículos no lanza veinte consultas de existencia', function (): void {
    /*
     * Cada resultado pedía su stock por separado: veinticuatro consultas de más POR CADA TECLA que
     * pulsa quien está buscando. No se ve en pantalla —solo se nota en que el mostrador va pesado a
     * media mañana— así que se fija con un número.
     */
    for ($i = 1; $i <= 20; $i++) {
        Product::create(['sku' => 'BOM-'.$i, 'name' => 'Bomba número '.$i, 'price' => '100']);
    }

    $consultas = 0;
    DB::listen(function () use (&$consultas): void {
        $consultas++;
    });

    app(ProductLookupPresenter::class)->search('bomba', 24);

    // La de productos, la de existencias y la de almacenes. El margen deja sitio a lo que el marco
    // haga por su cuenta sin permitir que vuelva una consulta por artículo.
    expect($consultas)->toBeLessThan(8);
});

// ------------------------------------------------------------------ La pantalla

it('la pantalla del mostrador trae la tabla, la ficha y las columnas que se adaptan', function (): void {
    /*
     * Se comprueba el MARCADO y no el aspecto: qué columna se pinta lo decide Alpine en el navegador
     * y aquí no hay navegador. Lo que sí se puede fijar es que los enganches sigan ahí, porque ya
     * pasó en este proyecto que una vista reescrita perdiera piezas enteras sin dar un solo error.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'dueno@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    expect($html)
        // Las columnas que solo se pintan si algún resultado trae ese dato.
        ->toContain('x-show="col.vehiculo"')
        ->toContain('x-show="col.ubicacion"')
        ->toContain('x-show="col.imagen"')
        // El número de parte y la marca van dentro de la celda del artículo, no en columna propia.
        ->toContain('x-if="p.part_number"')
        ->toContain('x-if="p.brand"')
        // La ficha y su cantidad, que es lo que evita pulsar «+» once veces para vender doce.
        ->toContain('agregarFicha()')
        ->toContain('x-model.number="fichaQty"')
        // Y el precio de solo lectura: el servidor lo relee de la base al cobrar.
        ->toContain('pos-solo-lectura')
        /*
         * El teclado. En un mostrador no se suelta el teclado para ir a marcar una fila con el
         * ratón, así que las flechas y el Enter no son un adorno: son el modo de trabajar.
         *
         * Lo que hacen esas funciones se comprobó en el navegador —una búsqueda, dos artículos
         * distintos al ticket sin volver a teclear—; lo que se fija aquí es que los enganches sigan
         * puestos, porque una vista reescrita puede perderlos sin dar un solo error.
         */
        ->toContain('@keydown.arrow-down.prevent="mover(1)"')
        ->toContain('@keydown.arrow-up.prevent="mover(-1)"')
        ->toContain('@keydown.enter.prevent="abrirMarcado()"')
        // Y la referencia que permite devolver el foco al buscador tras agregar, para que la
        // siguiente búsqueda se teclee encima sin borrar nada.
        ->toContain('x-ref="buscarInput"');
});

it('el precio de la ficha es de SOLO LECTURA', function (): void {
    /*
     * No es cosmético. Al cobrar, el servidor vuelve a leer el precio de la base e ignora lo que
     * mande el navegador. Un campo editable ahí enseñaría 1.800 y cobraría 2.450 sin avisar a nadie:
     * la peor clase de interfaz, la que miente en silencio.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño2',
        'email' => 'dueno2@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    expect($html)->toMatch('/:value="rd\(ficha\.price\)"[^>]*readonly/');
});
