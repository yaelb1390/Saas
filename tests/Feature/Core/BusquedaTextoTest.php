<?php

declare(strict_types=1);

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\BusquedaTexto;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Buscar texto sin que importen las mayúsculas, en cualquier base.
 *
 * Lo usan el mostrador, el monitoreo y el buscador de productos del bot de WhatsApp, así que un
 * fallo aquí no rompe una pantalla: rompe las tres a la vez. Ya ha pasado dos veces.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $empresa = app(CompanyService::class)->create(new CreateCompanyData(name: 'Buscadora'));
    app(CurrentCompany::class)->set($empresa->id);

    Product::create(['sku' => 'PRB-4471', 'name' => 'Bomba de agua', 'price' => '2450', 'cost' => '1000']);
});

/*
 * ESTE TEST MIRA EL SQL, Y NO ES POR GUSTO.
 *
 * La consulta llevaba `escape '\'`, y eso tumbó la búsqueda ENTERA en producción —mostrador,
 * monitoreo y bot de WhatsApp— mientras en local funcionaba perfectamente:
 *
 *     SQLSTATE[HY093]: Invalid parameter number: parameter was not defined
 *
 * En producción la conexión va con `DB_EMULATE_PREPARES=true`, que hace falta para el pooler de
 * Supabase. En ese modo PDO analiza el SQL él mismo para sustituir los `?`, y su analizador trata la
 * barra invertida como escape dentro de una cadena: al llegar a `escape '\'` da la cadena por no
 * terminada y deja de reconocer los `?` que vienen detrás.
 *
 * NO SE PUEDE PROBAR CON UN TEST DE COMPORTAMIENTO, y por eso este mira el SQL. En local no falla
 * —los tests corren sobre SQLite, y el PHP 8.4 de aquí analiza distinto que el 8.3 de producción—,
 * así que «buscar bomba encuentra Bomba» pasa igual con el fallo que sin él. Lo único que distingue
 * lo roto de lo sano en cualquier base y cualquier versión es que en el SQL no haya barra invertida.
 */
it('la consulta no lleva barras invertidas, que en produccion rompian a PDO', function (): void {
    $sql = '';
    DB::listen(function ($consulta) use (&$sql): void {
        if (str_contains($consulta->sql, 'products')) {
            $sql .= $consulta->sql;
        }
    });

    app(ProductRepositoryInterface::class)->search('bomba');

    // La barra por código: escrita a mano se pierde por el camino y el test dejaría de mirar nada.
    expect($sql)->not->toContain(chr(92))
        // Y el escape sigue estando, que sin él SQLite no protege los comodines.
        ->and($sql)->toContain("escape '!'");
});

/*
 * El comodín que escribe el usuario tiene que dejar de serlo: quien busca «100%» no puede acabar
 * buscando «cualquier cosa». Y el propio carácter de escape se escapa a sí mismo, o un artículo con
 * «!» en el nombre buscaría otra cosa distinta de la que se tecleó.
 */
it('los comodines del usuario se neutralizan, y el escape se escapa a si mismo', function (): void {
    expect(BusquedaTexto::patron('100%'))->toBe('%100!%%')
        ->and(BusquedaTexto::patron('a_b'))->toBe('%a!_b%')
        ->and(BusquedaTexto::patron('vaya!'))->toBe('%vaya!!%')
        ->and(BusquedaTexto::prefijo('100%'))->toBe('100!%%');
});

/*
 * Y la razón de ser del ayudante, que sigue en pie: en PostgreSQL `like` distingue mayúsculas, así
 * que sin bajar el texto la gente que escribe en minúsculas no encuentra nada. Se comprueba en el
 * SQL porque en SQLite `like` ya las ignora por su cuenta y un test de comportamiento no protege.
 */
it('la consulta baja el texto a minusculas', function (): void {
    $sql = '';
    DB::listen(function ($consulta) use (&$sql): void {
        if (str_contains($consulta->sql, 'products')) {
            $sql .= $consulta->sql;
        }
    });

    app(ProductRepositoryInterface::class)->search('bomba');

    expect($sql)->toContain('lower(name) like');
});
