<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Audit;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * El rastro no puede tumbar lo que registra.
 *
 * Aquí las migraciones se aplican A MANO y el despliegue no las corre, así que el código llega
 * siempre antes que el cambio en la base. Entre una cosa y la otra hay un hueco de minutos u horas
 * en el que la columna `company_id` de `audits` todavía no existe.
 *
 * Ese hueco NO puede significar que el negocio se pare. Los treinta modelos auditados escriben una
 * fila de rastro dentro de la misma operación: si el INSERT del rastro lleva una columna inexistente,
 * lo que se cae es la venta, no el registro de la venta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Duena',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');

    olvidarSiHayColumna();
});

afterEach(function (): void {
    olvidarSiHayColumna();
});

/**
 * Borra la respuesta cacheada de si la columna existe.
 *
 * Se cachea por proceso a propósito —una consulta al catálogo por venta sería caro— y la suite
 * comparte proceso entre tests, así que sin esto el segundo test heredaría la respuesta del primero
 * y no probaría nada.
 */
function olvidarSiHayColumna(): void
{
    $p = new ReflectionProperty(Audit::class, 'tieneEmpresa');
    $p->setAccessible(true);
    $p->setValue(null, null);
}

it('guarda la empresa en el rastro cuando la columna está', function (): void {
    $this->actingAs($this->owner);

    Category::create(['company_id' => $this->company->id, 'name' => 'Bebidas']);

    expect(Audit::query()->where('auditable_type', Category::class)->value('company_id'))
        ->toBe($this->company->id);
});

it('sin la columna, la operación sigue adelante en vez de morirse', function (): void {
    // Es exactamente el estado de producción entre el despliegue y la migración.
    Schema::table('audits', function ($t): void {
        // El índice va primero: apunta a la columna y SQLite no la deja soltar con él puesto.
        $t->dropIndex('audits_company_id_created_at_index');
        $t->dropColumn('company_id');
    });

    $this->actingAs($this->owner);

    $categoria = Category::create(['company_id' => $this->company->id, 'name' => 'Bebidas']);

    // Lo que importa: la categoría existe. El rastro se escribió sin empresa, que se rellena
    // hacia atrás cuando la migración pase.
    expect($categoria->exists)->toBeTrue()
        ->and(Audit::query()->where('auditable_type', Category::class)->count())->toBe(1);
});
