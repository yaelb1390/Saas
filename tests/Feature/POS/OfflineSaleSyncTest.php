<?php

declare(strict_types=1);

use App\Modules\Billing\Models\Invoice;
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
use App\Modules\POS\Services\OfflineSaleSyncService;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 * Subir las ventas que se cobraron sin internet.
 *
 * Todo lo que se prueba aquí parte de un hecho: ESTAS VENTAS YA OCURRIERON. El cliente pagó y se
 * fue. Por eso los fallos que importan no son «entró algo que no debía» sino los dos que hacen
 * perder dinero de verdad:
 *
 *   - cobrar dos veces al mismo cliente porque el envío se reintentó;
 *   - registrar un importe distinto del que dice el recibo que el cliente tiene en la mano.
 *
 * Ninguno de los dos da error en pantalla. Los dos se descubren cuadrando la caja, tarde.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado Sin Línea'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->warehouse = $this->company->warehouses()->where('is_default', true)->firstOrFail();
    $this->product = Product::create(['sku' => 'REF', 'name' => 'Refresco', 'cost' => '30', 'price' => '75']);
    app(StockService::class)->increase($this->product, $this->warehouse, StockMovementType::Purchase, '10');

    $this->session = app(CashService::class)->open(CashRegister::create(['name' => 'Caja 1']), '0');
    $this->sync = app(OfflineSaleSyncService::class);
});

/**
 * Una venta tal como la guarda el terminal en su cola.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function ventaOffline(array $cambios = []): array
{
    return [
        'uuid' => $cambios['uuid'] ?? (string) Str::uuid(),
        'cash_session_id' => $cambios['cash_session_id'] ?? test()->session->id,
        'payment_method' => 'cash',
        'paid' => $cambios['paid'] ?? '150',
        'lines' => $cambios['lines'] ?? [[
            'product_id' => test()->product->id,
            'quantity' => '2',
            'unit_price' => '75',
        ]],
    ];
}

it('el mismo envío dos veces deja UNA sola venta', function (): void {
    /*
     * El test que sostiene todo lo demás.
     *
     * Un terminal que recupera la línea no sabe si su envío anterior llegó: la respuesta pudo
     * perderse con la venta ya registrada. Su única salida sensata es reintentar, y eso solo es
     * seguro si repetir no cobra dos veces.
     */
    $venta = ventaOffline();

    $primera = $this->sync->sincronizar([$venta]);
    $segunda = $this->sync->sincronizar([$venta]);

    expect($primera[0]['estado'])->toBe('registrada')
        ->and($segunda[0]['estado'])->toBe('ya_estaba')
        // Y sobre todo: una fila, no dos.
        ->and(Sale::query()->count())->toBe(1)
        // Con el mismo código: el segundo envío devuelve la venta que ya existía, no otra.
        ->and($segunda[0]['code'])->toBe($primera[0]['code']);
});

it('el stock se descuenta una sola vez aunque se reintente', function (): void {
    // Descontar dos veces dejaría el inventario contando mercancía que sí está en el estante.
    $venta = ventaOffline();

    $this->sync->sincronizar([$venta]);
    $this->sync->sincronizar([$venta]);

    expect((string) Stock::query()->where('product_id', $this->product->id)->value('quantity'))
        ->toStartWith('8');
});

it('registra el precio que se cobró, no el que dice hoy el catálogo', function (): void {
    /*
     * El cliente tiene un recibo por 75. Si mientras el terminal estaba a oscuras alguien subió el
     * producto a 90, registrar 90 haría que el sistema dijera un número que nadie pagó: la caja
     * cuadraría de menos y el cajero cargaría con una diferencia que no cometió.
     */
    $venta = ventaOffline();
    $this->product->update(['price' => '90']);

    $resultado = $this->sync->sincronizar([$venta]);
    $sale = Sale::query()->firstOrFail();

    expect((string) $sale->items()->value('unit_price'))->toStartWith('75')
        ->and((string) $sale->total)->toStartWith('150')
        // Y no entra en silencio: queda escrito qué se aceptó.
        ->and($sale->offline_review)->toContain('75.00')
        ->and($sale->offline_review)->toContain('90.00')
        ->and($resultado[0]['revision'])->not->toBeNull();
});

it('sin existencia suficiente entra igual, en negativo y marcada', function (): void {
    /*
     * La mercancía ya salió por la puerta. Rechazar la venta no la devuelve al estante: solo hace
     * que el inventario diga que hay tres refrescos que no están.
     */
    $venta = ventaOffline([
        'paid' => '975',
        'lines' => [[
            'product_id' => $this->product->id,
            'quantity' => '13',   // hay 10
            'unit_price' => '75',
        ]],
    ]);

    $resultado = $this->sync->sincronizar([$venta]);

    expect($resultado[0]['estado'])->toBe('registrada')
        ->and((string) Stock::query()->where('product_id', $this->product->id)->value('quantity'))
        ->toStartWith('-3')
        ->and(Sale::query()->firstOrFail()->offline_review)->toContain('negativo');
});

it('una venta imposible no arrastra a las demás del lote', function (): void {
    // Ocho ventas de una mañana y una con un producto borrado: suben siete, no cero.
    $buena = ventaOffline();
    $imposible = ventaOffline(['lines' => [[
        'product_id' => 999999,
        'quantity' => '1',
        'unit_price' => '10',
    ]]]);
    $otraBuena = ventaOffline();

    $resultados = $this->sync->sincronizar([$buena, $imposible, $otraBuena]);

    expect($resultados[0]['estado'])->toBe('registrada')
        ->and($resultados[1]['estado'])->toBe('rechazada')
        ->and($resultados[1]['motivo'])->toContain('ya no existe')
        ->and($resultados[2]['estado'])->toBe('registrada')
        ->and(Sale::query()->count())->toBe(2);
});

it('una venta offline no consume ningún comprobante fiscal', function (): void {
    // Decidido a propósito: la numeración fiscal sale de una fila que se bloquea, y dos terminales
    // sin conexión cogerían el mismo NCF. El comprobante se emite después, con línea.
    $this->sync->sincronizar([ventaOffline()]);

    $sale = Sale::query()->firstOrFail();

    expect(Invoice::query()->where('sale_id', $sale->id)->exists())->toBeFalse();
});

it('la venta se queda en la caja de su turno aunque ya esté cerrada', function (): void {
    /*
     * Meterla en la sesión abierta de ahora descuadraría DOS arqueos: el de aquel turno por defecto
     * y el de este por exceso. Y el cajero de hoy cargaría con un sobrante que no es suyo.
     */
    $venta = ventaOffline();
    $cerrada = $this->session;
    app(CashService::class)->close($cerrada, '0');
    app(CashService::class)->open(CashRegister::create(['name' => 'Caja 2']), '0');

    $this->sync->sincronizar([$venta]);
    $sale = Sale::query()->firstOrFail();

    expect($sale->cash_session_id)->toBe($cerrada->id)
        ->and($sale->offline_review)->toContain('cerrada')
        /*
         * Y el arqueo de ese turno NO se tocó.
         *
         * Es la mitad importante del asunto: ese cierre ya se contó y se firmó. Sumarle ahora un
         * cobro convertiría un cuadre correcto en un descuadre y dejaría un dinero apareciendo de la
         * nada en un documento cerrado.
         */
        ->and(CashMovement::query()->where('cash_session_id', $cerrada->id)
            ->where('type', CashMovementType::Sale)->exists())->toBeFalse();
});

it('queda marcada la hora en que se subió, distinta de cuando se cobró', function (): void {
    // Sin esta marca, una venta de ayer por la tarde parecería de esta mañana y el arqueo del turno
    // de ayer no cuadraría con lo que dice el cajero que vendió.
    $this->sync->sincronizar([ventaOffline()]);

    expect(Sale::query()->firstOrFail()->synced_offline_at)->not->toBeNull();
});

it('un UUID de otra empresa no vale para colarse en esta', function (): void {
    /*
     * El aislamiento entre empresas manda también aquí. Si el UUID se buscara sin el scope, un
     * terminal podría preguntar por una venta ajena y descubrir su código.
     */
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'La de al lado'));
    $venta = ventaOffline();

    $this->sync->sincronizar([$venta]);

    app(CurrentCompany::class)->set($otra->id);
    $enLaOtra = Sale::query()->where('client_uuid', $venta['uuid'])->first();

    expect($enLaOtra)->toBeNull();
});
