<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

/*
 * Borrado definitivo de una empresa.
 *
 * Es la acción más destructiva del sistema y no tiene vuelta atrás. Lo que se cubre aquí es, sobre
 * todo, lo que NO debe pasar: que se borre sin confirmar quién eres, que se borre la empresa
 * equivocada, o que el borrado deje restos vivos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();

    $this->victima = app(CompanyService::class)->create(new CreateCompanyData(name: 'Prestamos BM'));
    $this->vecina = app(CompanyService::class)->create(new CreateCompanyData(name: 'PrestamosFM'));

    app(CurrentCompany::class)->set($this->victima->id);

    // Datos de negocio y un usuario, para comprobar que no sobreviven.
    Product::create(['name' => 'Helado', 'price' => '100', 'sku' => 'H-1']);
    $this->empleado = User::create([
        'company_id' => $this->victima->id, 'name' => 'Empleado',
        'email' => 'empleado@victima.test', 'password' => 'secret-password',
    ]);

    app(CurrentCompany::class)->forget();

    $this->super = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'super@plataforma.test',
        'password' => 'clave-del-operador', 'is_super_admin' => true,
    ]);
});

/** @param  array<string, mixed>  $extra */
function borrarEmpresa(Company $company, array $extra = []): TestResponse
{
    return test()->actingAs(test()->super)->delete(route('platform.companies.destroy', $company), array_merge([
        'password' => 'clave-del-operador',
        'confirm_name' => $company->name,
    ], $extra));
}

it('borra la empresa con sus datos y sus usuarios', function (): void {
    borrarEmpresa($this->victima)->assertRedirect(route('platform.companies'));

    expect(Company::withTrashed()->find($this->victima->id))->toBeNull()
        ->and(DB::table('products')->where('company_id', $this->victima->id)->count())->toBe(0)
        ->and(DB::table('users')->where('id', $this->empleado->id)->count())->toBe(0);
});

it('no deja usuarios sueltos al borrar la empresa', function (): void {
    // La clave foránea de users.company_id es SET NULL: si no se borraran a mano, sobrevivirían
    // vivos y sin empresa. Un usuario así no queda sujeto al aislamiento por empresa.
    borrarEmpresa($this->victima);

    expect(DB::table('users')->whereNull('company_id')->where('is_super_admin', false)->count())->toBe(0);
});

it('no deja restos en ninguna tabla de la empresa', function (): void {
    // Recorre TODAS las tablas con company_id en vez de comprobar unas pocas a mano: así, una
    // tabla nueva que se olvide de borrar aparece aquí sola.
    borrarEmpresa($this->victima);

    $restos = [];

    foreach (Schema::getTableListing() as $tabla) {
        $nombre = str_contains($tabla, '.') ? explode('.', $tabla)[1] : $tabla;

        if (! in_array('company_id', Schema::getColumnListing($nombre), true)) {
            continue;
        }

        $filas = DB::table($nombre)->where('company_id', $this->victima->id)->count();

        if ($filas > 0) {
            $restos[$nombre] = $filas;
        }
    }

    expect($restos)->toBe([]);
});

it('no toca a la empresa de al lado', function (): void {
    // «Prestamos BM» y «PrestamosFM» conviven en la plataforma: el borrado de una no puede rozar
    // a la otra.
    borrarEmpresa($this->victima);

    expect(Company::find($this->vecina->id))->not->toBeNull();
});

it('sin la contraseña correcta no borra nada', function (): void {
    borrarEmpresa($this->victima, ['password' => 'me-la-invento'])
        ->assertSessionHasErrors('password');

    expect(Company::find($this->victima->id))->not->toBeNull();
});

it('sin escribir el nombre exacto no borra nada', function (): void {
    // La protección contra borrar la empresa equivocada: teclear el nombre obliga a leer cuál es.
    borrarEmpresa($this->victima, ['confirm_name' => 'PrestamosFM'])
        ->assertSessionHasErrors('confirm_name');

    expect(Company::find($this->victima->id))->not->toBeNull()
        ->and(DB::table('products')->where('company_id', $this->victima->id)->count())->toBe(1);
});

it('perdona mayúsculas y espacios al escribir el nombre', function (): void {
    // Se busca que haya LEÍDO qué borra, no precisión de notario.
    borrarEmpresa($this->victima, ['confirm_name' => '  prestamos bm  '])->assertRedirect();

    expect(Company::withTrashed()->find($this->victima->id))->toBeNull();
});

it('un dueño de empresa no puede borrar empresas', function (): void {
    // Solo el operador de la plataforma. Sin esto, cualquiera podría borrar la empresa de otro.
    $dueno = withRole(User::create([
        'company_id' => $this->vecina->id, 'name' => 'Dueño',
        'email' => 'dueno@vecina.test', 'password' => 'secret-password',
    ]), 'owner');

    $this->actingAs($dueno)
        ->delete(route('platform.companies.destroy', $this->victima), [
            'password' => 'secret-password', 'confirm_name' => 'Prestamos BM',
        ])
        ->assertForbidden();

    expect(Company::find($this->victima->id))->not->toBeNull();
});

it('deja de tener empresa activa si borró la que tenía puesta', function (): void {
    // Si no, la sesión apuntaría a una empresa que ya no existe.
    $this->actingAs($this->super)->withSession(['active_company_id' => $this->victima->id])
        ->delete(route('platform.companies.destroy', $this->victima), [
            'password' => 'clave-del-operador', 'confirm_name' => 'Prestamos BM',
        ])
        ->assertRedirect()
        ->assertSessionMissing('active_company_id');
});
