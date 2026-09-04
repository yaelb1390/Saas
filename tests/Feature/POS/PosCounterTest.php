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
use App\Modules\POS\Support\PosProfile;
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
        // La fila manda el artículo AL TICKET de un gesto, igual que el lector de esta misma
        // pantalla y que el resto del sistema. No abre un formulario.
        ->toContain('@click="elegir(i)"')
        /*
         * La rejilla: el ticket es el documento. La columna de la clave y la fila en blanco donde
         * escribe el lector son lo que la distingue de una lista de tarjetas.
         */
        ->toContain('pos-rejilla')
        ->toContain('item.sku')
        ->toContain('x-model.number="nuevaCant"')
        // El campo del lector vive AHÍ, no en una caja aparte: tenerlo en los dos sitios habría sido
        // otra vez dos maneras de hacer lo mismo.
        ->toContain('x-ref="scanInput"')
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
        // El Enter lo atiende scan(): código exacto, sugerencia o aviso, según lo que haya.
        ->toContain('@keydown.enter.prevent="scan()"')
        // Teclear en la celda de clave BUSCA. Es lo que hace que «bom» encuentre las bombas en vez
        // de dar «código no encontrado».
        ->toContain('@input.debounce.250ms="searchProducts()"');
});

it('la ficha SOLO INFORMA: no pide cantidad, ni descuento, ni tiene botón de agregar', function (): void {
    /*
     * Es el arreglo de fondo de todo esto.
     *
     * La ficha llegó a tener su propia cantidad y su propio descuento, y el ticket ya tenía los dos.
     * Dos sitios para el mismo dato, que es justo lo que este proyecto evita a propósito en otras
     * partes —`ProductLookupPresenter` existe, y su comentario lo dice, para que «no haya dos
     * verdades»—. Y no era teórico: agregar dos veces el mismo artículo pisaba el descuento que se
     * hubiera escrito en el ticket.
     *
     * Se comprueba por AUSENCIA porque es lo único que impide que vuelvan a colarse.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño2',
        'email' => 'dueno2@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    expect($html)->not->toContain('fichaQty')
        ->and($html)->not->toContain('fichaDesc')
        ->and($html)->not->toContain('agregarFicha')
        // El precio de la ficha se enseña como cifra, no como campo: un campo que no se puede
        // escribir invita a intentarlo, y al cobrar el servidor lo relee de la base igualmente.
        ->and($html)->not->toContain('pos-solo-lectura')
        ->and($html)->toContain('pos-ficha-precio');
});

it('la cantidad del ticket se escribe SIN activar la venta por peso', function (): void {
    /*
     * Vender doce tornillos costaba once pulsaciones de «+»: el campo de cantidad solo aparecía con
     * la opción «Cantidad decimal» encendida, que es para quien vende por peso o medida. Un colmado
     * que despacha doce unidades no tiene por qué activar los decimales para poder teclear un doce.
     *
     * El perfil de esta empresa NO tiene decimales, y aun así el campo tiene que estar.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño3',
        'email' => 'dueno3@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    // El perfil de esta empresa no tiene los decimales encendidos: el campo debe estar igualmente.
    expect(PosProfile::for($this->company->fresh())['options']['decimal_qty'])->toBeFalse();

    // El campo existe, y su paso es de uno: quien vende unidades no debe poder teclear 2,5 tornillos.
    expect($html)->toContain('aria-label="Cantidad"')
        ->and($html)->toMatch('/step="1"[^>]*\n?[^>]*x-model\.number="item\.qty"|x-model\.number="item\.qty"/');
});

it('el campo del lector vive DENTRO de la rejilla, y fuera del formulario de cobro', function (): void {
    /*
     * Las dos mitades importan.
     *
     * DENTRO de la rejilla: el lector de pistola es un teclado y escribe en el campo enfocado, así
     * que la celda de la clave es su destino natural. Tener además una caja de escaneo aparte serían
     * dos sitios para lo mismo.
     *
     * FUERA del formulario de cobro: ahí el Enter del lector enviaría el formulario y cobraría una
     * venta a medio armar. El formulario solo necesita el carrito en su campo oculto, así que la
     * rejilla puede quedarse fuera y estar a salvo por estructura, no por que un `.prevent` no falle.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño4',
        'email' => 'dueno4@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    // El campo está una sola vez en toda la pantalla.
    expect(substr_count($html, 'x-ref="scanInput"'))->toBe(1);

    // Y cae antes de que empiece el formulario de cobro.
    $campo = strpos($html, 'x-ref="scanInput"');
    $formulario = strpos($html, 'action="'.route('panel.pos.checkout').'"');

    expect($formulario)->not->toBeFalse()
        ->and($campo)->toBeLessThan($formulario);
});

it('el almacén del turno se ve en la pantalla', function (): void {
    /*
     * Quien cobra tiene que poder saber de dónde sale lo que vende sin ir a otra pantalla. Antes no
     * se podía: el mostrador descontaba del almacén de por omisión pasara lo que pasara, y eso no se
     * decía en ninguna parte.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño5',
        'email' => 'dueno5@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    expect($html)->toContain('pos-almacen-barra')
        ->and($html)->toContain(Warehouse::query()->where('is_default', true)->value('name'));
});

it('hay UN SOLO campo de texto para buscar y escanear', function (): void {
    /*
     * Había dos: una caja de búsqueda que aceptaba nombres y la celda de clave que exigía el código
     * exacto. Quien atendía tenía que saber cuál usar, y teclear «bom» en la celda no encontraba nada
     * aunque hubiera tres bombas en el catálogo.
     *
     * Se comprueba por AUSENCIA del segundo, que es lo único que impide que vuelva a colarse.
     */
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño6',
        'email' => 'dueno6@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($this->company->id, $dueno);

    $html = $this->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();

    expect(substr_count($html, 'type="search"'))->toBe(0)
        ->and(substr_count($html, 'x-ref="scanInput"'))->toBe(1)
        ->and($html)->not->toContain('buscarInput');
});

it('lo que EMPIEZA por lo tecleado sale antes que lo que solo lo contiene', function (): void {
    /*
     * Quien teclea «bomb» está pensando en «Bomba», no en «Aceite para bomba».
     *
     * EL CASO ESTÁ ELEGIDO PARA QUE EL ALFABETO NO BASTE. Un primer intento usaba «Turbo bomba», que
     * por orden alfabético ya caía al final: el test pasaba en verde con el orden por prefijo quitado
     * y no protegía nada. «Aceite para bomba» empieza por A, así que ordenando por nombre saldría
     * PRIMERO; solo el orden por prefijo lo manda al final.
     */
    Product::create(['sku' => 'Z-1', 'name' => 'Aceite para bomba hidráulica', 'price' => '900']);
    Product::create(['sku' => 'Z-2', 'name' => 'Bombillo LED', 'price' => '120']);

    $nombres = collect(app(ProductLookupPresenter::class)->search('bomb'))->pluck('name')->all();

    expect($nombres)->toHaveCount(3)
        // Lo que empieza por «bomb», delante. Y del beforeEach viene «Bomba de agua».
        ->and($nombres[0])->toStartWith('Bomb')
        ->and($nombres[1])->toStartWith('Bomb')
        // Lo que solo la contiene, al final, aunque el alfabeto lo pondría el primero.
        ->and($nombres[2])->toBe('Aceite para bomba hidráulica');
});

// ------------------------------------------------------------------ La rejilla editable del ticket

/**
 * Deja la pantalla del mostrador rendida y devuelve su HTML.
 */
function htmlDelMostrador(int $companyId, string $correo): string
{
    $dueno = withRole(User::create([
        'company_id' => $companyId, 'name' => 'Dueño',
        'email' => $correo, 'password' => 'secret-password',
    ]), 'owner');

    conCajaAbierta($companyId, $dueno);

    return test()->actingAs($dueno)->get(route('panel.pos'))->assertOk()->getContent();
}

/*
 * EL TEST QUE SUJETA EL DISEÑO DE LAS DOS MAQUETACIONES.
 *
 * El ticket se pinta de dos maneras —rejilla de tablet para arriba, tarjetas en teléfono— pero el
 * campo del lector tiene que existir UNA sola vez. Si algún día alguien lo duplica dentro de cada
 * maquetación, habrá dos elementos con el mismo `id` y el mismo `x-ref`: el lector de pistola
 * escribirá en el que no se ve y las ventas se meterán en el vacío, sin un solo error en consola.
 */
it('el ticket trae las dos maquetaciones y el campo del lector una sola vez', function (): void {
    $html = htmlDelMostrador($this->company->id, 'rejilla@mostrador.test');

    expect($html)
        ->toContain('x-ref="rejillaTicket"')          // la rejilla editable
        ->toContain('bmos-tabla-envoltura md:hidden') // las tarjetas del teléfono
        ->and(substr_count($html, 'id="pos-scan"'))->toBe(1)
        ->and(substr_count($html, 'x-ref="scanInput"'))->toBe(1);
});

/*
 * EL PRECIO NO SE EDITA, Y ESO ES UNA REGLA DE SEGURIDAD, NO UN DETALLE.
 *
 * Al cobrar, el servidor relee el precio de la base e ignora lo que mande el navegador. Una celda
 * editable aquí dejaría teclear 1.00 en un artículo de 1000 y cobrar 1000 igual: la pantalla diría
 * una cosa y el recibo otra. Si alguien añade `editable` a esa columna, este test cae.
 */
it('la columna del precio no se declara editable', function (): void {
    $html = htmlDelMostrador($this->company->id, 'precio@mostrador.test');

    // El trozo de la definición de esa columna, hasta que se cierra.
    preg_match("/colId: 'price'.*?\},/s", $html, $m);

    expect($m)->not->toBeEmpty()
        ->and($m[0])->not->toContain('editable');
});

/*
 * Qué columnas existen lo decide el perfil del negocio, en PHP, y viaja a la rejilla ya resuelto.
 * Si eso se decidiera también en JavaScript habría dos sitios donde encenderlo y un día
 * discreparían: la pantalla enseñaría el descuento por línea y el servidor lo ignoraría.
 */
it('las columnas opcionales viajan resueltas desde el perfil, no decididas en el navegador', function (): void {
    $html = htmlDelMostrador($this->company->id, 'perfil@mostrador.test');

    /*
     * El objeto de configuración que recibe la rejilla, con una clave por opción del perfil.
     *
     * Las comillas van como `"` porque así las escribe la directiva `@js` de Laravel, que
     * envuelve el objeto en un `JSON.parse`. Se comprueba tal cual sale al HTML y no una versión
     * idealizada: un test que dé por hecho otro escapado pasaría o fallaría por el motivo equivocado.
     */
    // La barra invertida se arma por código a propósito: escrita a mano en el fuente es de las cosas
    // que se pierden por el camino sin que nadie lo note, y el test pasaría a comprobar otra cosa.
    $comilla = chr(92).'u0022';

    expect($html)
        ->toContain($comilla.'descuento'.$comilla)
        ->toContain($comilla.'serie'.$comilla)
        ->toContain($comilla.'empleado'.$comilla)
        ->toContain($comilla.'nota'.$comilla)
        ->toContain($comilla.'paso'.$comilla)
        // Y que la rejilla lo LEE de ahí, en vez de decidirlo por su cuenta.
        ->toContain('const c = this.rejillaConfig;');
});

// ------------------------------------------------------------------ Teclear el artículo a mano

/*
 * TECLEAR A MANO NO TENÍA NI UN TEST, y es la mitad del trabajo del mostrador.
 *
 * El lector sí estaba cubierto. Pero un dependiente que no tiene el código —la etiqueta se despegó,
 * el artículo se vende a granel, el cliente lo pide por su nombre— escribe unas letras, y de ahí
 * salen las sugerencias. Ese camino entero estaba sin probar: se podía romper la búsqueda y la suite
 * seguiría verde.
 */
it('teclear unas letras devuelve el artículo, con la misma forma que un escaneo', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'teclea@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $datos = $this->actingAs($dueno)
        ->getJson(route('panel.pos.search', ['q' => 'bomb']))
        ->assertOk()
        ->json('results');

    expect($datos)->toHaveCount(1)
        ->and($datos[0]['name'])->toBe('Bomba de agua')
        ->and($datos[0]['sku'])->toBe('PRB-4471')
        // La misma forma que devuelve el lector: el terminal pinta las dos igual.
        ->and($datos[0])->toHaveKeys(['id', 'sku', 'name', 'price', 'stock']);
});

it('también encuentra por la clave, no solo por el nombre', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'clave@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $datos = $this->actingAs($dueno)
        ->getJson(route('panel.pos.search', ['q' => 'PRB-44']))
        ->assertOk()
        ->json('results');

    expect($datos)->toHaveCount(1)
        ->and($datos[0]['sku'])->toBe('PRB-4471');
});

/*
 * DESDE LA PRIMERA LETRA.
 *
 * Antes hacían falta dos, y en un mostrador eso se nota: el dependiente teclea la inicial, no
 * aparece nada, y no sabe si es que el artículo no está o que el sistema aún no ha buscado.
 *
 * Lo que permite bajarlo no es este umbral, es el TOPE: la respuesta se corta en 24 filas, así que
 * una «b» no trae medio catálogo. Si alguien quita ese tope, esto deja de ser barato.
 */
it('busca desde la primera letra', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'unaletra@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $datos = $this->actingAs($dueno)
        ->getJson(route('panel.pos.search', ['q' => 'b']))
        ->assertOk()
        ->json('results');

    expect($datos)->toHaveCount(1)
        ->and($datos[0]['name'])->toBe('Bomba de agua');
});

/*
 * El vacío sigue sin buscar: eso no es una búsqueda corta, es no haber empezado. Sin esta línea,
 * abrir la pantalla dispararía una consulta que devuelve las primeras 24 filas del catálogo.
 */
it('el campo vacío no dispara ninguna búsqueda', function (): void {
    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'vacio@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)
        ->getJson(route('panel.pos.search', ['q' => '   ']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

/*
 * EL AISLAMIENTO. La búsqueda del mostrador devuelve un artículo a partir de un texto: si fallara,
 * un negocio vería el catálogo del vecino —nombres, claves y precios— sin dejar rastro.
 */
it('lo tecleado nunca encuentra artículos de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Vecina'));
    app(CurrentCompany::class)->set($otra->id);
    Product::create(['sku' => 'AJENA-1', 'name' => 'Bomba ajena', 'price' => '100', 'cost' => '50']);
    app(CurrentCompany::class)->set($this->company->id);

    $dueno = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'aislada@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $datos = $this->actingAs($dueno)
        ->getJson(route('panel.pos.search', ['q' => 'bomba']))
        ->assertOk()
        ->json('results');

    expect(collect($datos)->pluck('name')->all())->not->toContain('Bomba ajena');
});
