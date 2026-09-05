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
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
