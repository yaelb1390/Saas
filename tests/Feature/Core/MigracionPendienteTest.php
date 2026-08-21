<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * Qué pasa cuando el código llega antes que la migración.
 *
 * NO es hipotético: aquí las migraciones se aplican a mano y el despliegue no las corre, así que
 * entre que sale el código y alguien migra pasan horas. En ese hueco, la pantalla de Redes sociales
 * SE CAYÓ EN PRODUCCIÓN con un 500 —y con ella publicar y conectar cuentas, que no tienen nada que
 * ver con la función nueva—.
 *
 * La regla que fijan estos tests: una tabla que falta puede dejar sin la función nueva, nunca tumbar
 * la pantalla que la aloja.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    DbTable::olvidar();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    $this->company->update(['modules' => ['social'], 'social_api_key' => 'sk_'.str_repeat('a', 64)]);
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Duena',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');
});

afterEach(fn () => DbTable::olvidar());

it('Redes sociales se pinta aunque falte la tabla de la bienvenida', function (): void {
    // El caso exacto que se cayó en producción.
    Schema::drop('social_welcome_settings');
    DbTable::olvidar();

    $this->actingAs($this->owner)->get(route('panel.social'))
        ->assertOk()
        // Lo que importa: publicar sigue ahí. La bienvenida, no.
        ->assertSee('Tus cuentas')
        ->assertDontSee('Bienvenida automática');
});

it('y al intentar guardarla se dice el motivo en vez de reventar', function (): void {
    Schema::drop('social_welcome_settings');
    DbTable::olvidar();

    $this->actingAs($this->owner)
        ->put(route('panel.social.welcome'), ['message' => 'Hola'])
        ->assertRedirect()
        ->assertSessionHas('panel_error');
});

it('Monitoreo se pinta aunque falte la tabla del registro', function (): void {
    // Es la pantalla a la que se va cuando algo va mal: la última que puede caerse.
    Schema::drop('system_events');
    DbTable::olvidar();

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('Registro del sistema');
});

it('y borrar lo viejo tampoco revienta sin esa tabla', function (): void {
    Schema::drop('system_events');
    DbTable::olvidar();

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op2@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->post(route('platform.monitoring.clean'))->assertRedirect();
});
