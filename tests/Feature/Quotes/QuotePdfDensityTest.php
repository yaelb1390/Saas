<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DocumentDensity;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Quotes\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El algoritmo de densidad/paginación del PDF.
 *
 * La regla que manda: TODAS las líneas se pintan siempre, sin importar cuántas sean. La densidad
 * solo cambia tipografía/espaciado; nunca oculta, recorta ni pagina de forma que se pierda una fila.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Multiservicios'));
    app(CurrentCompany::class)->set($this->company->id);
});

it('los umbrales de densidad son los documentados', function (): void {
    expect(DocumentDensity::paraCantidad(1))->toBe(DocumentDensity::NORMAL)
        ->and(DocumentDensity::paraCantidad(10))->toBe(DocumentDensity::NORMAL)
        ->and(DocumentDensity::paraCantidad(11))->toBe(DocumentDensity::COMPACT)
        ->and(DocumentDensity::paraCantidad(25))->toBe(DocumentDensity::COMPACT)
        ->and(DocumentDensity::paraCantidad(26))->toBe(DocumentDensity::ULTRA)
        ->and(DocumentDensity::paraCantidad(100))->toBe(DocumentDensity::ULTRA);
});

/** Una cotización con $n líneas de "concepto libre", cada una con texto distinto. */
function cotizacionConLineas(int $n): \App\Modules\Quotes\Models\Quote
{
    $lineas = [];
    for ($i = 1; $i <= $n; $i++) {
        $lineas[] = ['description' => "Concepto número {$i}", 'quantity' => '1', 'unit_price' => '100'];
    }

    return app(QuoteService::class)->crear($lineas, ['customer_name' => 'Cliente de prueba']);
}

it('con 1, 3, 5, 10, 20, 50 y 100 líneas, todas aparecen en el PDF', function (int $n) {
    $quote = cotizacionConLineas($n)->load('items', 'user');

    $html = view('quotes.pdf', ['quote' => $quote, 'company' => $this->company, 'logo' => null])->render();

    // Se comprueba la primera, una del medio y la última: si el algoritmo recortara o paginara
    // perdiendo filas, alguna de las tres fallaría.
    expect($html)->toContain('Concepto número 1')
        ->and($html)->toContain('Concepto número '.intdiv($n + 1, 2))
        ->and($html)->toContain('Concepto número '.$n)
        // Ninguna línea se esconde ni se recorta: nunca overflow:hidden, display:none ni recorte.
        ->and($html)->not->toContain('overflow:hidden')
        ->and($html)->not->toContain('display:none');
})->with([1, 3, 5, 10, 20, 50, 100]);

it('el nivel de densidad que se pinta corresponde al número de líneas', function (): void {
    $pocas = cotizacionConLineas(3)->load('items', 'user');
    $muchas = cotizacionConLineas(50)->load('items', 'user');

    $htmlPocas = view('quotes.pdf', ['quote' => $pocas, 'company' => $this->company, 'logo' => null])->render();
    $htmlMuchas = view('quotes.pdf', ['quote' => $muchas, 'company' => $this->company, 'logo' => null])->render();

    expect($htmlPocas)->toContain('data-densidad="normal"')
        ->and($htmlMuchas)->toContain('data-densidad="ultra"');
});

it('la tabla repite el encabezado por página: usa thead/tbody de verdad', function (): void {
    $quote = cotizacionConLineas(50)->load('items', 'user');

    $html = view('quotes.pdf', ['quote' => $quote, 'company' => $this->company, 'logo' => null])->render();

    expect($html)->toContain('<thead>')
        ->and($html)->toContain('display: table-header-group');
});
