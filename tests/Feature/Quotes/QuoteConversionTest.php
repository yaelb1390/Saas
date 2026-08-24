<?php

declare(strict_types=1);

use App\Modules\Cash\Enums\CashMovementType;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Services\QuoteConverter;
use App\Modules\Quotes\Services\QuoteService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Cobrar una cotización.
 *
 * Es el momento en que un documento se convierte en dinero y en mercancía que sale por la puerta, y
 * por eso todo lo que se vigila aquí es de la misma familia: que se cobre EL PRECIO QUE EL CLIENTE
 * ACEPTÓ, y que se cobre UNA SOLA VEZ.
 *
 * Ninguno de los dos fallos da error en pantalla. Los dos se descubren cuadrando la caja.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería El Progreso'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->producto = Product::create(['sku' => 'CEM', 'name' => 'Cemento', 'cost' => '300', 'price' => '450']);
    app(StockService::class)->increase($this->producto, $this->warehouse, StockMovementType::Purchase, '20');

    $this->sesion = app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');
    $this->quotes = app(QuoteService::class);
    $this->converter = app(QuoteConverter::class);
});

/** Una cotización de dos sacos de cemento al precio del catálogo. */
function cotizacionDeCemento(?string $precio = null): Quote
{
    return test()->quotes->crear([
        ['product_id' => test()->producto->id, 'quantity' => '2', 'unit_price' => $precio ?? '450'],
    ], ['customer_name' => 'Juan', 'customer_phone' => '18095551234']);
}

it('la venta cobra exactamente el total de la cotización', function (): void {
    /*
     * El número que el cliente leyó y aceptó tiene que ser el número que paga.
     *
     * Si el total de la venta no es el de la cotización, el sistema le está cobrando algo que nadie
     * aceptó, y la diferencia aparece en la caja al cierre sin que nadie sepa de dónde salió.
     */
    $quote = cotizacionDeCemento();

    $venta = $this->converter->convertir($quote);

    expect((string) $venta->total)->toBe((string) $quote->total)
        ->and((string) $venta->subtotal)->toBe((string) $quote->subtotal)
        ->and((string) $venta->tax)->toBe((string) $quote->tax);
});

it('se cobra el precio COTIZADO aunque el catálogo ya diga otro', function (): void {
    /*
     * El caso que da sentido a cotizar.
     *
     * Se ofertó el saco a 400. Al día siguiente sube a 500 en el catálogo. El cliente llega con su
     * papel: se le cobra 400, porque eso es lo que se le prometió por escrito y con fecha.
     */
    $quote = cotizacionDeCemento('400');
    $this->producto->update(['price' => '500']);

    $venta = $this->converter->convertir($quote);

    expect((string) $venta->items()->value('unit_price'))->toStartWith('400')
        ->and((string) $venta->total)->toBe((string) $quote->total);
});

it('avisa de la diferencia de precio antes de cobrar, sin cambiarla', function (): void {
    // Quien decide es la persona que tiene al cliente delante: puede respetar lo ofertado o
    // renegociar, pero tiene que enterarse. Cambiarlo en silencio sería decidir por ella.
    $quote = cotizacionDeCemento('400');
    $this->producto->update(['price' => '500']);

    $avisos = $this->converter->diferencias($quote);

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0])->toContain('400.00')
        ->and($avisos[0])->toContain('500.00');
});

it('convertir dos veces deja UNA sola venta', function (): void {
    /*
     * El doble clic con el cliente delante es el caso normal, no el raro. Y dos ventas de lo mismo
     * significan cobrar dos veces y descontar dos veces el inventario.
     */
    $quote = cotizacionDeCemento();

    $primera = $this->converter->convertir($quote);
    $segunda = $this->converter->convertir($quote->refresh());

    expect($segunda->id)->toBe($primera->id)
        ->and(Sale::query()->count())->toBe(1);
});

it('el stock baja una vez y el dinero entra una vez', function (): void {
    $quote = cotizacionDeCemento();

    $this->converter->convertir($quote);
    $this->converter->convertir($quote->refresh());

    expect((string) Stock::query()->where('product_id', $this->producto->id)->value('quantity'))
        ->toStartWith('18')
        ->and(CashMovement::query()->where('type', CashMovementType::Sale)->count())->toBe(1);
});

it('una cotización caducada no se cobra', function (): void {
    /*
     * Es para lo que sirve la fecha de validez. Cobrar el precio de hace tres meses porque nadie
     * miró el papel es exactamente lo que esa fecha viene a impedir.
     */
    $quote = cotizacionDeCemento();
    $quote->forceFill(['valid_until' => now()->subDay()])->save();

    expect(fn () => $this->converter->convertir($quote->refresh()))
        ->toThrow(RuntimeException::class, 'caducó');
});

it('una cotización rechazada no se cobra', function (): void {
    // El cliente dijo que no. Cobrarla sería cobrar algo que nadie aceptó.
    $quote = cotizacionDeCemento();
    $this->quotes->marcarEstado($quote, QuoteStatus::Rejected);

    expect(fn () => $this->converter->convertir($quote->refresh()))
        ->toThrow(RuntimeException::class, 'rechazada');
});

it('sin caja abierta no se cobra, igual que en el mostrador', function (): void {
    // El dinero de esta venta tiene que entrar en el arqueo de alguien. Sin caja quedaría un cobro
    // que no aparece en ningún cierre.
    $quote = cotizacionDeCemento();
    app(CashService::class)->close($this->sesion, '0');

    expect(fn () => $this->converter->convertir($quote->refresh()))
        ->toThrow(RuntimeException::class, 'caja abierta');
});

it('la mano de obra también se cobra: el total con servicios cuadra al céntimo', function (): void {
    /*
     * EL TEST QUE FALTABA, y lo destapó probarlo con números reales en el navegador.
     *
     * Antes las líneas sin producto se saltaban al convertir, con un aviso en pantalla. Una
     * cotización de RD$1.895 con RD$1.500 de mano de obra registraba una venta de RD$395: el negocio
     * perdía mil quinientos pesos cada vez que alguien no leyera el aviso. Un aviso no cobra.
     *
     * Ahora se cobran colgadas de un producto de servicios sin existencias, y el total de la venta
     * es exactamente el que el cliente aceptó.
     */
    $quote = $this->quotes->crear([
        ['product_id' => $this->producto->id, 'quantity' => '1', 'unit_price' => '450'],
        ['description' => 'Instalación', 'quantity' => '1', 'unit_price' => '1000'],
    ], ['customer_name' => 'Juan']);

    $venta = $this->converter->convertir($quote, ['paid' => '1450']);

    expect((string) $venta->total)->toBe((string) $quote->total)
        ->and($venta->items()->count())->toBe(2)
        // Y el concepto viaja en la nota: en el recibo, «Servicios y mano de obra» no le dice nada al
        // cliente, pero «Instalación» sí.
        ->and($venta->items()->pluck('note')->contains('Instalación'))->toBeTrue();
});

it('el producto de servicios no descuenta existencias ni sale en el catálogo', function (): void {
    /*
     * No es mercancía: no hay nada que restar de un almacén. Y va fuera del catálogo activo para que
     * no aparezca en el punto de venta, donde no pinta nada: no es algo que se venda tocándolo en una
     * rejilla, es el asiento de un concepto que ya se cotizó.
     */
    $quote = $this->quotes->crear([
        ['description' => 'Transporte', 'quantity' => '1', 'unit_price' => '800'],
    ], ['customer_name' => 'Juan']);

    $this->converter->convertir($quote, ['paid' => '800']);

    $servicios = Product::withTrashed()->where('sku', 'SERVICIOS')->firstOrFail();

    expect($servicios->track_stock)->toBeFalse()
        ->and($servicios->is_active)->toBeFalse()
        ->and($servicios->sePuedeVender())->toBeFalse()
        // Ni un movimiento de existencias por algo que no está en ningún estante.
        ->and(Stock::query()->where('product_id', $servicios->id)->exists())->toBeFalse();
});

it('dos cotizaciones con servicios no crean dos productos de servicios', function (): void {
    // Uno por empresa. Si cada cotización creara el suyo, el inventario acabaría con cincuenta filas
    // llamadas igual, que es justo lo que se quería evitar al permitir líneas sin producto.
    foreach (['Instalación', 'Transporte'] as $concepto) {
        $q = $this->quotes->crear(
            [['description' => $concepto, 'quantity' => '1', 'unit_price' => '500']],
            ['customer_name' => 'Juan'],
        );
        $this->converter->convertir($q, ['paid' => '500']);
    }

    expect(Product::withTrashed()->where('sku', 'SERVICIOS')->count())->toBe(1);
});

it('la cotización queda marcada como convertida y apunta a su venta', function (): void {
    $quote = cotizacionDeCemento();

    $venta = $this->converter->convertir($quote);
    $quote->refresh();

    expect($quote->status)->toBe(QuoteStatus::Converted)
        ->and($quote->sale_id)->toBe($venta->id);
});
