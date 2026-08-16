<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\HrService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Portal del empleado.
 *
 * No tenía ni un solo test, y por eso nadie vio durante meses que para la mayoría de los usuarios era
 * un callejón sin salida: el enlace «Mi portal» se ofrecía a todo el mundo y la pantalla solo decía
 * «Tu usuario no está vinculado a un empleado», sin explicar qué significaba ni qué hacer.
 *
 * El caso «sin ficha» no es el raro: el dueño de un colmado no suele estar en su propia plantilla.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sin esto, los tests que pintan una vista dependen de que alguien haya compilado los assets
    // antes de ejecutarlos.
    $this->withoutVite();

    app(CurrentCompany::class)->forget();
    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Portal Co'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Dueña',
        'email' => 'duena@portal.test', 'password' => 'secret-password',
    ]), 'owner');
});

it('enseña la ficha y las asistencias de quien está en la plantilla', function (): void {
    // Se contrata y se ficha por el camino de verdad (HrService), no creando el modelo a mano: es lo
    // que recorre el usuario real y lo que probaba la versión original de este test.
    $ficha = app(HrService::class)->hire(new CreateEmployeeData(
        name: 'Dueña', position: 'Gerente', userId: $this->owner->id,
    ));
    app(HrService::class)->clockIn($ficha);

    $this->actingAs($this->owner)->get(route('portal.employee'))
        ->assertOk()
        ->assertSee('Dueña')
        ->assertSee('Gerente')
        ->assertSee('Asistencias recientes');
});

it('a quien no tiene ficha le explica qué pasa y por dónde se arregla', function (): void {
    // Antes decía solo «no estás vinculado» y ahí terminaba. Un aviso sin salida deja a quien lo lee
    // pensando que algo se rompió.
    $this->actingAs($this->owner)->get(route('portal.employee'))
        ->assertOk()
        ->assertSee('no tiene ficha de empleado')
        ->assertSee(route('panel.users'));
});

it('a quien no puede arreglarlo no se le manda a una pantalla que le dará un 403', function (): void {
    // Cambiar un callejón sin salida por otro no es arreglarlo: al cajero se le dice a quién pedírselo.
    $cajero = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Cajero',
        'email' => 'cajero@portal.test', 'password' => 'secret-password',
    ]), 'staff');

    $html = $this->actingAs($cajero)->get(route('portal.employee'))->assertOk()->getContent();

    expect($html)->toContain('pídeselo a tu encargado')
        ->and($html)->not->toContain(route('panel.users'));
});

it('el menú no ofrece «Mi portal» a quien no es empleado', function (): void {
    // Un enlace que no lleva a ningún sitio es peor que no tenerlo.
    $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain(route('portal.employee'));
});

it('y sí lo ofrece en cuanto tiene ficha', function (): void {
    Employee::create([
        'name' => 'Dueña', 'position' => 'Gerente', 'is_active' => true,
        'user_id' => $this->owner->id,
    ]);

    $html = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain(route('portal.employee'))
        // El dueño tiene `delivery.own`, así que también ve el atajo a sus entregas.
        ->and($html)->toContain(route('portal.deliveries'));
});

it('un empleado no ve la ficha de otro', function (): void {
    // La consulta va por `user_id`; si se torciera, el portal enseñaría el salario de un compañero.
    Employee::create(['name' => 'Ajeno', 'position' => 'Cajero', 'is_active' => true]);

    $this->actingAs($this->owner)->get(route('portal.employee'))
        ->assertOk()
        ->assertDontSee('Ajeno');
});
