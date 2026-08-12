<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * Solo existe UNA puerta de registro.
 *
 * Fortify trae su propio registro y lo llama «register.store», igual que el nuestro. Con dos rutas
 * compartiendo nombre, el formulario acababa enviando a la de Fortify, que exige un campo «name»
 * inexistente en nuestro formulario: el alta fallaba con «The name field is required» estando todo
 * relleno.
 *
 * El daño de fondo era mayor: esa ruta crea un usuario SUELTO, sin empresa ni rol. Un usuario sin
 * company_id nunca activa el filtro de empresa, así que navegaría sin el aislamiento multiempresa.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El cliente elige el plan al registrarse, así que tiene que haber alguno en venta.
    $this->plan = Plan::create([
        'name' => 'Empresarial', 'slug' => 'empresarial', 'price' => '3000',
        'billing_cycle' => 'monthly', 'trial_days' => 0, 'modules' => null, 'is_active' => true,
    ]);
});

it('el nombre register.store apunta a nuestro registro, no al de Fortify', function (): void {
    // La causa exacta del fallo: si vuelve a haber dos rutas con este nombre, gana la última
    // registrada y el formulario se va a la puerta equivocada.
    expect(route('register.store'))->toEndWith('/registro');
});

it('la puerta de registro de Fortify no existe', function (): void {
    expect(Route::has('register'))->toBeFalse();

    $this->post('/register', [
        'name' => 'Colado',
        'email' => 'colado@ejemplo.test',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertMethodNotAllowed();

    // Lo que de verdad importa: no queda ninguna cuenta suelta, sin empresa.
    expect(User::query()->whereNull('company_id')->count())->toBe(0);
});

it('la dirección antigua /register lleva al registro bueno', function (): void {
    // Puede estar guardada en marcadores o compartida. Reenviar es mejor que un 404 seco.
    $this->get('/register')->assertRedirect('/registro');

    // Pero solo por GET: enviar datos a la dirección vieja no crea nada ni finge que sí.
    $this->post('/register', ['name' => 'Colado', 'email' => 'x@y.test', 'password' => 'secret-password'])
        ->assertMethodNotAllowed();

    expect(User::query()->whereNull('company_id')->count())->toBe(0);
});

it('el registro propio crea la cuenta con su empresa', function (): void {
    $this->post(route('register.store'), [
        'company_name' => 'PrestamosBM',
        'owner_name' => 'Juana',
        'owner_email' => 'juana@prestamosbm.test',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $owner = User::firstWhere('email', 'juana@prestamosbm.test');

    expect($owner)->not->toBeNull()
        ->and($owner->name)->toBe('Juana')
        // Sin empresa, el aislamiento multiempresa no se aplicaría a esta cuenta.
        ->and($owner->company_id)->not->toBeNull();
});
