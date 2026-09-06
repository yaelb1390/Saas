<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Support\MasVendidos;
use App\Modules\Sales\Support\TopeDeDescuento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Mostrador Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueño',
        'email' => 'owner@mostrador.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'FIL-1', 'name' => 'Filtro', 'cost' => '50', 'price' => '250']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');

    /*
     * EL TURNO ABIERTO, que ahora hace falta para facturar.
     *
     * Va en el `beforeEach` y no test a test a propósito. Al exigir caja, los que comprueban OTROS
     * fallos —sin secuencia de NCF, crédito fiscal sin RNC— se quedaron en verde pero fallando por
     * falta de turno: pasaban por el motivo equivocado, que es la peor clase de test verde porque
     * ya no protege lo que dice proteger.
     */
    $this->caja = CashRegister::create([
        'company_id' => $this->company->id, 'name' => 'Caja 1', 'code' => 'CAJA-01', 'is_active' => true,
    ]);
    $this->turno = CashSession::create([
        'company_id' => $this->company->id,
        'cash_register_id' => $this->caja->id,
        'user_id' => $this->owner->id,
        'status' => 'open',
        'opening_amount' => '1000',
        'opened_at' => now(),
    ]);
});

function ncfSequence(NcfType $type = NcfType::Consumo): FiscalSequence
{
    return FiscalSequence::create([
        'type' => $type, 'next_number' => 1, 'range_from' => 1,
        'range_to' => 1000, 'number_length' => 8, 'is_active' => true,
    ]);
}

function counterCart(int $productId, int $qty = 1): string
{
    return json_encode([['id' => $productId, 'qty' => $qty]]);
}

it('factura desde el mostrador: crea la venta, descuenta stock y emite NCF', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 2),
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_ok')
        ->assertSessionHas('pos_receipt_id');

    $invoice = Invoice::first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->ncf)->toBe('B0200000001')
        ->and(Sale::count())->toBe(1)
        ->and((float) $this->product->fresh()->totalStock())->toBe(8.0); // 10 - 2
});

it('stock insuficiente revierte todo: ni venta ni factura', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 99), // solo hay 10
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and((float) $this->product->fresh()->totalStock())->toBe(10.0);
});

it('sin secuencia de NCF activa revierte la venta', function (): void {
    // No se crea ninguna FiscalSequence.
    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 1),
            'type' => 'B02', 'paid' => '100000',
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and((float) $this->product->fresh()->totalStock())->toBe(10.0); // stock intacto
});

it('crédito fiscal sin RNC revierte (la DGII exige identificar al cliente)', function (): void {
    ncfSequence(NcfType::CreditoFiscal);

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 1),
            'type' => 'B01', 'paid' => '100000', // B01 exige customer_tax_id
        ])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('el ticket vacío no factura', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), ['cart' => '[]', 'type' => 'B02', 'paid' => '0'])
        ->assertRedirect()
        ->assertSessionHas('panel_error');

    expect(Invoice::count())->toBe(0);
});

it('un usuario sin permiso invoices.issue no accede al mostrador', function (): void {
    $noPerm = User::create([
        'company_id' => $this->company->id, 'name' => 'Sin permiso',
        'email' => 'noperm@mostrador.test', 'password' => 'secret-password',
    ]);

    $this->actingAs($noPerm)->get(route('panel.parts'))->assertForbidden();
    $this->actingAs($noPerm)
        ->post(route('panel.parts.invoice'), ['cart' => counterCart($this->product->id), 'type' => 'B02', 'paid' => '250'])
        ->assertForbidden();
});

it('la búsqueda del mostrador devuelve la pieza en JSON', function (): void {
    $this->actingAs($this->owner)
        ->getJson(route('panel.parts.search', ['q' => 'filtro']))
        ->assertOk()
        ->assertJsonPath('results.0.name', 'Filtro');
});

// ------------------------------------------------------------------ El turno es obligatorio

/** Un ticket con cantidad y descuento por línea, como lo manda ahora la pantalla. */
function ticketDe(int $productId, string $qty = '1', string $descuento = '0'): string
{
    return json_encode([['id' => $productId, 'qty' => $qty, 'discount' => $descuento]]);
}

/** Cierra el turno abierto del `beforeEach`, para probar la pantalla sin caja. */
function conTurnoCerrado(): void
{
    CashSession::query()->update(['status' => 'closed', 'closed_at' => now()]);
}

/*
 * SIN TURNO NO SE FACTURA, y esto es lo más importante de la etapa.
 *
 * Antes sí se podía: la venta se registraba igual pero se quedaba fuera de todo arqueo, así que el
 * dinero cobrado no aparecía en el cierre y el descuadre se descubría al contar el efectivo, sin
 * forma de saber de qué factura venía. Si este test se pone verde con la caja cerrada, ese agujero
 * ha vuelto.
 */
it('sin caja abierta no se factura, y no queda ni venta ni comprobante', function (): void {
    ncfSequence();
    conTurnoCerrado();

    $respuesta = $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
    ])->assertRedirect();

    /*
     * Se exige EL MENSAJE, y no solo que no haya venta.
     *
     * Sin esta comprobación el test pasaría igual si la guarda desapareciera y el cobro reventara
     * por cualquier otro motivo: la transacción revertiría, no habría venta, y el test seguiría en
     * verde sin proteger nada. El mensaje es lo que distingue «se rechazó a propósito» de «se
     * rompió por casualidad».
     */
    $respuesta->assertSessionHas('panel_error', 'No hay una caja abierta. Abre el turno para poder facturar.');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        // Y el stock no se movió: la transacción revirtió entera.
        ->and($this->product->fresh()->totalStock())->toBe('10.000');
});

it('sin caja abierta la pantalla ofrece abrirla en vez del ticket', function (): void {
    conTurnoCerrado();

    $html = $this->actingAs($this->owner)->get(route('panel.parts'))->assertOk()->getContent();

    expect($html)->toContain('Caja cerrada')
        ->toContain(route('panel.parts.open-session'))
        // El ticket no se pinta: no hay nada que armar si no se puede cobrar.
        ->not->toContain('id="parts-search"');
});

/*
 * Y hay que PODER abrirla desde aquí. La apertura del punto de venta vive tras `module:pos`, así que
 * sin esta ruta una empresa que solo contrató Facturación se habría quedado mirando una pantalla que
 * le pide un turno que no tiene forma de abrir.
 */
it('se puede abrir la caja desde el propio mostrador', function (): void {
    conTurnoCerrado();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.open-session'), ['opening_amount' => '500'])
        ->assertRedirect();

    $abierta = CashSession::query()->where('status', 'open')->latest('opened_at')->first();

    expect($abierta)->not->toBeNull()
        ->and($abierta->opening_amount)->toBe('500.00');
});

// ------------------------------------------------------------------ Cantidad decimal y descuento

/*
 * LA CANTIDAD ADMITE DECIMALES, y antes no.
 *
 * El controlador la forzaba con un `(int)`, así que medio kilo se facturaba como cero —y con el
 * `max(1, ...)`, como uno—. La columna de la base ya era decimal(15,3) y el DTO la lleva como
 * cadena: era la pantalla la que redondeaba, no el dominio.
 */
it('factura media unidad sin redondearla a uno', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '0.5'),
        'type' => NcfType::Consumo->value,
        'paid' => '125',
    ])->assertRedirect();

    $venta = Sale::query()->latest('id')->firstOrFail();

    expect((float) $venta->items->first()->quantity)->toBe(0.5)
        ->and($venta->total)->toBe('125.00')
        // Y el stock bajó medio, no uno entero.
        ->and($this->product->fresh()->totalStock())->toBe('9.500');
});

/*
 * EL DESCUENTO QUEDA REGISTRADO COMO DESCUENTO, y eso es justo lo que lo hace mejor que tocar el
 * precio: en la venta se distingue de un artículo barato, y se puede auditar después quién rebajó
 * qué. Si algún día se permite escribir el precio, esa traza desaparece.
 */
it('el descuento por linea baja el importe y queda guardado aparte del precio', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '1', '50'),
        'type' => NcfType::Consumo->value,
        'paid' => '200',
    ])->assertRedirect();

    $linea = Sale::query()->latest('id')->firstOrFail()->items->first();

    expect($linea->unit_price)->toBe('250.00')
        ->and($linea->discount)->toBe('50.00')
        ->and($linea->subtotal)->toBe('200.00');
});

/*
 * EL PRECIO NO SE TOCA, mande lo que mande el navegador. Es la regla que sostiene todo lo demás: si
 * el precio viajara desde el cliente, cualquiera podría facturarse un artículo de 250 por 1.
 */
it('ignora el precio que mande el navegador y relee el del catalogo', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => json_encode([['id' => $this->product->id, 'qty' => '1', 'price' => '1', 'unit_price' => '1']]),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
    ])->assertRedirect();

    expect(Sale::query()->latest('id')->firstOrFail()->total)->toBe('250.00');
});

// ------------------------------------------------------------------ La forma de pago y la caja

/*
 * SOLO EL EFECTIVO ENGORDA EL ARQUEO. Antes no se preguntaba la forma de pago y todo se registraba
 * como efectivo: una factura cobrada con tarjeta inflaba el cajón, y el cierre salía con un sobrante
 * que nadie sabía explicar.
 */
it('cobrar con tarjeta no mete dinero en el cajon', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
        'payment_method' => 'card',
    ])->assertRedirect();

    $venta = Sale::query()->latest('id')->firstOrFail();

    expect($venta->payment_method)->toBe('card')
        // La venta sí queda ligada al turno; lo que no hay es movimiento de efectivo.
        ->and($venta->cash_session_id)->toBe($this->turno->id)
        ->and(DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->count())->toBe(0);
});

it('cobrar en efectivo si mete el importe en el cajon', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
        'payment_method' => 'cash',
    ])->assertRedirect();

    $movimiento = DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->first();

    expect($movimiento)->not->toBeNull()
        ->and((float) $movimiento->amount)->toBe(250.0);
});

/*
 * El crédito no se ofrece en el mostrador —no es un cobro al contado— y el servidor no puede fiarse
 * de que el navegador respete eso: ante cualquier forma de pago que no sea de mostrador, efectivo.
 */
it('una forma de pago que no es de mostrador cae en efectivo', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
        'payment_method' => 'credit',
    ])->assertRedirect();

    expect(Sale::query()->latest('id')->firstOrFail()->payment_method)->toBe('cash');
});

// ------------------------------------------------------------------ La pantalla

it('la cabecera dice de que turno y con que comprobante se esta facturando', function (): void {
    ncfSequence();

    $html = $this->actingAs($this->owner)->get(route('panel.parts'))->assertOk()->getContent();

    expect($html)->toContain('Caja abierta')
        ->toContain('Cajero')
        // El próximo NCF viaja resuelto desde el servidor, para poder avisar ANTES de armar el
        // ticket de que ese tipo de comprobante no tiene secuencia activa.
        ->toContain('B0200000001')
        ->toContain('proximoNcf');
});

it('el resumen desglosa subtotal, ITBIS y total', function (): void {
    $html = $this->actingAs($this->owner)->get(route('panel.parts'))->assertOk()->getContent();

    expect($html)->toContain('Subtotal')
        ->toContain('ITBIS')
        ->toContain('bmos-mostrador-total');
});

// ------------------------------------------------------------------ El buscador de clientes

/*
 * EL AISLAMIENTO, que es lo que más importa aquí.
 *
 * Esta ruta devuelve nombres, RNC y teléfonos a partir de un texto. Si fallara, un negocio vería la
 * cartera de clientes del vecino —con sus identificadores fiscales— tecleando dos letras, y sin
 * dejar el menor rastro: nadie revisa los registros buscando búsquedas de clientes.
 */
it('el buscador de clientes nunca devuelve los de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Vecina'));
    app(CurrentCompany::class)->set($otra->id);
    Customer::create(['name' => 'Ramirez Ajeno', 'tax_id' => '131234567', 'is_active' => true]);
    app(CurrentCompany::class)->set($this->company->id);

    Customer::create(['name' => 'Ramirez Propio', 'tax_id' => '101010101', 'is_active' => true]);

    /*
     * Los nombres van SIN TILDE a propósito, y no por descuido.
     *
     * La búsqueda distingue acentos: `lower()` baja las mayúsculas pero no quita la tilde, así que
     * teclear «ramirez» no encuentra «Ramírez». Es una limitación real y anotada aparte —en un país
     * donde media clientela se apellida Peña o Ramírez, importa—, pero este test es del AISLAMIENTO
     * entre empresas: con tildes fallaría por el motivo equivocado y taparía lo que viene a vigilar.
     */
    $datos = $this->actingAs($this->owner)
        ->getJson(route('panel.parts.customers', ['q' => 'ramirez']))
        ->assertOk()
        ->json('results');

    expect(collect($datos)->pluck('name')->all())
        ->toContain('Ramirez Propio')
        ->not->toContain('Ramirez Ajeno');
});

/*
 * Se busca por las cuatro cosas por las que un cliente se identifica en el mostrador. La del
 * teléfono no es un capricho: quien llama para recoger una pieza dice su número, no el nombre con el
 * que lo dieron de alta hace tres años.
 */
it('encuentra al cliente por nombre, RNC, cedula y telefono', function (): void {
    Customer::create([
        'name' => 'Ferretería Peña', 'tax_id' => '131889977',
        'cedula' => '00112345678', 'phone' => '8095551234', 'is_active' => true,
    ]);

    foreach (['ferret', '131889', '0011234', '80955'] as $termino) {
        $datos = $this->actingAs($this->owner)
            ->getJson(route('panel.parts.customers', ['q' => $termino]))
            ->assertOk()
            ->json('results');

        expect($datos)->toHaveCount(1, "no encontró por «{$termino}»")
            ->and($datos[0]['name'])->toBe('Ferretería Peña');
    }
});

/*
 * Archivar un cliente significaba justo esto: que deje de ofrecerse al facturar. El filtro estaba en
 * el controlador y se ha movido al presenter; este test es el que garantiza que no se perdió por el
 * camino al cambiar de sitio.
 */
it('un cliente archivado no se ofrece para facturar', function (): void {
    Customer::create(['name' => 'Cliente Archivado', 'is_active' => false]);

    $datos = $this->actingAs($this->owner)
        ->getJson(route('panel.parts.customers', ['q' => 'archivado']))
        ->assertOk()
        ->json('results');

    expect($datos)->toBeEmpty();
});

/*
 * Para el crédito fiscal hace falta un identificador válido. Si el cliente tiene RNC se usa ese; si
 * no, su cédula. La regla se resuelve en el servidor y no en el navegador para que no discrepe de la
 * que aplica el comprobante.
 */
it('devuelve el RNC y, si no lo hay, la cedula', function (): void {
    Customer::create(['name' => 'Con RNC', 'tax_id' => '131889977', 'cedula' => '00112345678', 'is_active' => true]);
    Customer::create(['name' => 'Solo Cedula', 'cedula' => '00187654321', 'is_active' => true]);

    $conRnc = $this->actingAs($this->owner)->getJson(route('panel.parts.customers', ['q' => 'Con RNC']))->json('results');
    $soloCedula = $this->actingAs($this->owner)->getJson(route('panel.parts.customers', ['q' => 'Solo Cedula']))->json('results');

    expect($conRnc[0]['tax_id'])->toBe('131889977')
        ->and($soloCedula[0]['tax_id'])->toBe('00187654321');
});

/*
 * LA GANANCIA DE RENDIMIENTO, y por eso se comprueba.
 *
 * La pantalla cargaba TODOS los clientes activos de la empresa en un desplegable, en cada visita.
 * Si alguien vuelve a meter ese `foreach`, este test cae. Con dos mil clientes no es un detalle: es
 * la diferencia entre que la pantalla abra o que el cajero espere.
 */
it('la pantalla ya no trae todos los clientes dentro del HTML', function (): void {
    foreach (range(1, 30) as $i) {
        Customer::create(['name' => "Cliente {$i}", 'is_active' => true]);
    }

    $html = $this->actingAs($this->owner)->get(route('panel.parts'))->assertOk()->getContent();

    expect($html)->not->toContain('Cliente 17')
        // Y en su lugar está el buscador.
        ->toContain(route('panel.parts.customers'));
});

// ------------------------------------------------------------------ Productos rápidos

/*
 * Salen de las ventas REALES, no de una lista configurada. Una lista a mano se rellena el primer día
 * y nadie vuelve a tocarla; lo que se despacha a diario lo dicen las ventas, y se adapta solo a cada
 * negocio sin pedirle a nadie que configure nada.
 */
it('los productos rapidos salen de lo que mas se ha vendido', function (): void {
    ncfSequence();

    $poco = Product::create(['sku' => 'POCO-1', 'name' => 'Se vende poco', 'cost' => '10', 'price' => '20']);
    app(StockService::class)->increase($poco, $this->warehouse, StockMovementType::Purchase, '50');

    // El «Filtro» del beforeEach se vende 5; el otro, 1.
    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => json_encode([
            ['id' => $this->product->id, 'qty' => '5', 'discount' => '0'],
            ['id' => $poco->id, 'qty' => '1', 'discount' => '0'],
        ]),
        'type' => NcfType::Consumo->value,
        'paid' => '100000',
    ])->assertRedirect();

    Cache::flush();

    $ids = app(MasVendidos::class)->ids(8);

    expect($ids)->toHaveCount(2)
        // Por cantidad despachada, no por número de tickets: en un mostrador se llevan diez tornillos
        // de una vez y un filtro, y lo que hay que tener a mano es lo que más sale por la puerta.
        ->and($ids[0])->toBe($this->product->id);
});

it('los productos rapidos nunca son los de otra empresa', function (): void {
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La Vecina'));
    app(CurrentCompany::class)->set($otra->id);
    $ajeno = Product::create(['sku' => 'AJENO-1', 'name' => 'Pieza ajena', 'cost' => '1', 'price' => '2']);
    app(CurrentCompany::class)->set($this->company->id);

    Cache::flush();

    expect(app(MasVendidos::class)->ids(8))->not->toContain($ajeno->id);
});

/*
 * Sin ventas todavía no se pinta la sección: seis botones vacíos no ayudan a nadie, y un negocio que
 * abre hoy no tiene por qué ver un hueco donde debería haber algo.
 */
it('sin ventas todavia no hay productos rapidos', function (): void {
    Cache::flush();

    expect(app(MasVendidos::class)->ids(8))->toBeEmpty();
});

// ------------------------------------------------------------------ El tope de descuento

/**
 * Un usuario que puede facturar pero NO puede rebajar sin tope.
 *
 * Con los roles de fábrica ese caso no se da en esta pantalla: `invoices.issue` solo lo tienen dueño
 * y admin, y los dos tienen también `sales.discount`. El tope está pensado para roles a medida —y
 * para el punto de venta, donde sí cobra el cajero—, así que aquí se construye a mano el usuario que
 * lo sufre. Sin este montaje, el tope no se probaría nunca.
 */
function cajeroQueFactura(int $companyId): User
{
    $usuario = User::create([
        'company_id' => $companyId, 'name' => 'Cajero que factura',
        'email' => 'cajero.factura@mostrador.test', 'password' => 'secret-password',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
    $usuario->givePermissionTo('invoices.issue');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $usuario;
}

/*
 * EL TOPE SE COMPRUEBA EN EL SERVIDOR, no solo en pantalla.
 *
 * Hasta ahora no había ni permiso ni límite: cualquiera con acceso al cobro podía aplicar un 100 % y
 * el servidor lo aceptaba sin rechistar. La pantalla avisa para no hacer perder el tiempo, pero quien
 * manda es esto: una petición a mano con el descuento inflado tiene que encontrarse el mismo muro.
 */
it('sin permiso, un descuento por encima del tope se rechaza', function (): void {
    ncfSequence();
    $cajero = cajeroQueFactura($this->company->id);

    // 250 de bruto; el tope de fábrica es el 10 %, o sea 25.
    $respuesta = $this->actingAs($cajero)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '1', '100'),
        'type' => NcfType::Consumo->value,
        'paid' => '250',
    ])->assertRedirect();

    $respuesta->assertSessionHas('panel_error');

    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

it('sin permiso, un descuento dentro del tope si pasa', function (): void {
    ncfSequence();
    $cajero = cajeroQueFactura($this->company->id);

    $this->actingAs($cajero)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '1', '25'),   // justo el 10 % de 250
        'type' => NcfType::Consumo->value,
        'paid' => '225',
    ])->assertRedirect()->assertSessionHas('panel_ok');

    expect(Sale::query()->latest('id')->firstOrFail()->items->first()->discount)->toBe('25.00');
});

/*
 * Quien tiene el permiso rebaja libre. Es la otra mitad de la regla: si el dueño no pudiera pasar del
 * tope, el permiso no serviría de nada y habría que ir a los ajustes para cada excepción.
 */
it('con permiso se puede rebajar por encima del tope', function (): void {
    ncfSequence();

    // El dueño del `beforeEach` tiene `sales.discount` por su rol.
    $this->actingAs($this->owner)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '1', '200'),
        'type' => NcfType::Consumo->value,
        'paid' => '50',
    ])->assertRedirect()->assertSessionHas('panel_ok');

    expect(Sale::query()->latest('id')->firstOrFail()->total)->toBe('50.00');
});

/*
 * Cada empresa fija el suyo: una ferretería y una cafetería no tienen el mismo margen. El ajuste de
 * la empresa manda sobre el valor de fábrica.
 */
it('la empresa puede fijar su propio tope', function (): void {
    ncfSequence();
    $cajero = cajeroQueFactura($this->company->id);

    $this->company->update(['settings' => ['discount_max_percent' => '50']]);

    // 100 de rebaja sobre 250 es el 40 %: pasaría del 10 % de fábrica, pero no del 50 % de la empresa.
    $this->actingAs($cajero)->post(route('panel.parts.invoice'), [
        'cart' => ticketDe($this->product->id, '1', '100'),
        'type' => NcfType::Consumo->value,
        'paid' => '150',
    ])->assertRedirect()->assertSessionHas('panel_ok');

    expect(Sale::count())->toBe(1);
});

/*
 * EL TOPE SE MIDE CONTRA EL BRUTO, no contra lo ya rebajado.
 *
 * Si se midiera sobre el neto, el porcentaje se calcularía sobre un número que el propio descuento
 * encoge, y el límite daría de sí cuanto más se rebaja: con un tope del 10 %, rebajar la mitad de la
 * venta acabaría pasando la comprobación a base de rebajar más. Este test fija la base correcta.
 */
it('el tope se mide sobre el precio sin rebajar', function (): void {
    $topes = app(TopeDeDescuento::class);
    $cajero = cajeroQueFactura($this->company->id);
    $this->actingAs($cajero);

    // Bruto 1000, tope 10% => 100 de rebaja como máximo.
    expect($topes->maximoEnDinero($cajero, '1000'))->toBe('100.00')
        ->and($topes->excedido($cajero, '1000', '100'))->toBeFalse()
        ->and($topes->excedido($cajero, '1000', '100.01'))->toBeTrue();
});

it('un tope disparatado en los ajustes se ignora', function (): void {
    $topes = app(TopeDeDescuento::class);

    // Un negativo dejaría el mostrador sin poder rebajar un peso; un 500 % abriría la mano del todo.
    $this->company->update(['settings' => ['discount_max_percent' => '-5']]);
    expect($topes->porcentaje())->toBe('10');

    $this->company->update(['settings' => ['discount_max_percent' => '500']]);
    expect($topes->porcentaje())->toBe('10');
});

it('la pantalla le dice al usuario hasta cuanto puede rebajar', function (): void {
    $cajero = cajeroQueFactura($this->company->id);

    $html = $this->actingAs($cajero)->get(route('panel.parts'))->assertOk()->getContent();

    // Viaja el tope; con permiso viajaría `null` y la pantalla no avisaría de nada.
    expect($html)->toContain('topeDescuento');
});

// ------------------------------------------------------------------ El cobro repartido, de punta a punta

/*
 * Los tests finos del reparto viven en `tests/Feature/Sales/PagoMixtoTest.php` y en el unitario. Este
 * comprueba lo que ninguno de esos puede: que el MOSTRADOR sepa mandarlo. Entre el formulario y el
 * servicio hay un `json_decode`, un filtro por formas admitidas y un `number_format`, y ahí es donde
 * se pierde un cobro sin que salte nada.
 */
it('el mostrador factura un cobro repartido y solo mete el efectivo al cajon', function (): void {
    ncfSequence();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 4),   // 4 x 250 = 1000
            'type' => 'B02',
            'payments' => json_encode([
                ['method' => 'card', 'amount' => 400, 'reference' => 'AUTH-77'],
                ['method' => 'cash', 'amount' => 600],
            ]),
        ])
        ->assertSessionMissing('panel_error');

    $venta = Sale::firstOrFail();

    expect($venta->total)->toBe('1000.00')
        ->and($venta->payments)->toHaveCount(2)
        ->and($venta->desglose()->efectivo())->toBe('600.00')
        // La referencia del datáfono llega hasta la fila: es lo que permite casar el cobro con el
        // extracto del banco cuando algo no cuadra.
        ->and($venta->payments->firstWhere('method', PaymentMethod::Card)->reference)->toBe('AUTH-77')
        ->and(Invoice::count())->toBe(1);

    // Y al cajón, solo los 600 en billetes.
    $enCaja = DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->sum('amount');
    expect((float) $enCaja)->toBe(600.0);
});

/*
 * LA DEGRADACIÓN QUE DICE QUE NO.
 *
 * En producción las migraciones se aplican a mano, así que entre que sale este código y alguien migra
 * la tabla no existe. Aceptar el reparto ahí obligaría a colapsarlo en una sola vía: el TOTAL ENTERO
 * al cajón de una venta cobrada mitad con tarjeta. Un cobro rechazado se repite en cinco segundos;
 * un arqueo falso no se descubre hasta que alguien cuenta el dinero.
 */
it('sin la tabla de pagos el cobro repartido se rechaza con un aviso', function (): void {
    ncfSequence();

    Schema::drop('sale_payments');
    DbTable::olvidar();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 4),
            'type' => 'B02',
            'payments' => json_encode([
                ['method' => 'card', 'amount' => 400],
                ['method' => 'cash', 'amount' => 600],
            ]),
        ])
        ->assertSessionHas('panel_error');

    // Nada a medias: ni venta, ni factura, ni NCF gastado, ni movimiento de caja.
    expect(Sale::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(FiscalSequence::firstOrFail()->next_number)->toBe(1)
        ->and(DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->count())->toBe(0);
});

/*
 * Pero con una sola forma de pago el mostrador tiene que seguir facturando sin la tabla, que es lo
 * que hará el 100 % de las ventas hasta que alguien migre.
 */
it('sin la tabla de pagos el cobro de una sola forma sigue funcionando', function (): void {
    ncfSequence();

    Schema::drop('sale_payments');
    DbTable::olvidar();

    $this->actingAs($this->owner)
        ->post(route('panel.parts.invoice'), [
            'cart' => counterCart($this->product->id, 4),
            'type' => 'B02',
            'paid' => '1000',
        ])
        ->assertSessionMissing('panel_error');

    expect(Sale::count())->toBe(1)
        ->and(Invoice::count())->toBe(1);

    $enCaja = DB::table('cash_movements')->where('cash_session_id', $this->turno->id)->sum('amount');
    expect((float) $enCaja)->toBe(1000.0);
});
