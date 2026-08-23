<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Audit;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Services\PlatformHealthService;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * El monitoreo de la plataforma.
 *
 * Lo que más se cubre aquí es LA TRAMPA: un super administrador siempre tiene una empresa activa
 * —el middleware lo fija a la de su sesión o a la primera por id— así que una consulta normal en
 * esta pantalla devolvería los datos de UNA empresa haciéndolos pasar por los de la plataforma, sin
 * dar error y sin que nadie lo note mientras haya una sola empresa de prueba.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    $this->primera = app(CompanyService::class)->create(new CreateCompanyData(name: 'Primera'));
    $this->segunda = app(CompanyService::class)->create(new CreateCompanyData(name: 'Segunda'));

    app(CurrentCompany::class)->set($this->primera->id);

    $this->super = User::create([
        'company_id' => $this->primera->id, 'name' => 'Operador',
        'email' => 'super@monitor.test', 'password' => 'secret-password',
        'is_super_admin' => true,
    ]);

    $this->duena = withRole(User::create([
        'company_id' => $this->primera->id, 'name' => 'Dueña',
        'email' => 'duena@monitor.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------------- Quién entra

it('solo el operador de la plataforma ve el monitoreo', function (): void {
    $this->actingAs($this->duena)->get(route('platform.monitoring'))->assertForbidden();
    $this->actingAs($this->super)->get(route('platform.monitoring'))->assertOk();
});

// ------------------------------------------------------------------------- La trampa del aislamiento

it('cuenta las empresas de TODAS, no las de la que tenga abierta', function (): void {
    /*
     * Es el fallo más caro de esta pantalla. Con el ámbito de empresa puesto, esto devolvería 1 —la
     * empresa activa— y pasaría por bueno hasta que alguien contara a mano.
     */
    $resumen = app(PlatformHealthService::class)->calcular();

    expect($resumen['empresas'])->toBe(2);
});

it('la actividad enseña la de todas las empresas', function (): void {
    Customer::create(['company_id' => $this->primera->id, 'name' => 'De la primera']);

    app(CurrentCompany::class)->set($this->segunda->id);
    Customer::create(['company_id' => $this->segunda->id, 'name' => 'De la segunda']);

    // El operador vuelve a tener la PRIMERA activa, que es como llega a la pantalla.
    app(CurrentCompany::class)->set($this->primera->id);

    expect(Audit::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(Audit::query()->where('company_id', $this->segunda->id)->count())->toBeGreaterThan(0);
});

// ------------------------------------------------------------------------- La actividad

it('cada registro guarda a qué empresa pertenece', function (): void {
    // Sin esto, «¿qué pasó en la empresa X?» solo se podría responder uniendo contra los treinta
    // tipos de modelo auditado, uno por uno.
    Customer::create(['company_id' => $this->primera->id, 'name' => 'Ana']);

    $registro = Audit::query()->where('auditable_type', Customer::class)->latest('id')->firstOrFail();

    expect($registro->company_id)->toBe($this->primera->id);
});

it('se puede filtrar la actividad por empresa', function (): void {
    Customer::create(['company_id' => $this->primera->id, 'name' => 'De la primera']);

    app(CurrentCompany::class)->set($this->segunda->id);
    Customer::create(['company_id' => $this->segunda->id, 'name' => 'De la segunda']);
    app(CurrentCompany::class)->set($this->primera->id);

    $this->actingAs($this->super)
        ->get(route('platform.monitoring', ['empresa' => $this->segunda->id]))
        ->assertOk()
        ->assertSee('Segunda');
});

it('los nombres de modelo salen en español', function (): void {
    // «App\Modules\CRM\Models\Customer» no es información para nadie.
    Customer::create(['company_id' => $this->primera->id, 'name' => 'Ana']);

    $this->actingAs($this->super)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('Cliente')
        ->assertDontSee('App\Modules\CRM\Models\Customer');
});

it('borra lo de más de un año y respeta lo reciente', function (): void {
    Customer::create(['company_id' => $this->primera->id, 'name' => 'Ana']);
    $reciente = Audit::query()->latest('id')->firstOrFail();

    $viejo = Audit::query()->create([
        'event' => 'created', 'auditable_type' => Customer::class, 'auditable_id' => 1,
        'company_id' => $this->primera->id,
    ]);
    $viejo->forceFill(['created_at' => now()->subYears(2)])->save();

    $this->actingAs($this->super)->post(route('platform.monitoring.clean'))->assertRedirect();

    expect(Audit::query()->whereKey($viejo->id)->exists())->toBeFalse()
        ->and(Audit::query()->whereKey($reciente->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------------------- Los errores

it('el mismo fallo dos veces es UNA fila con su contador', function (): void {
    /*
     * Si no se agrupara, el primer error en bucle llenaría la tabla y enterraría a los demás, que
     * es justo cuando más falta hace poder leerla.
     */
    $e = new RuntimeException('algo falló');

    ErrorEvent::anotar($e, ['a.php:1'], 'http://x/y', $this->primera->id, $this->super->id);
    ErrorEvent::anotar($e, ['a.php:1'], 'http://x/y', $this->primera->id, $this->super->id);

    $filas = ErrorEvent::query()->get();

    expect($filas)->toHaveCount(1)->and($filas->first()->hits)->toBe(2);
});

it('una clave dentro del mensaje se guarda tachada', function (): void {
    // Un error de una API suele traer la credencial dentro; guardarla aquí sería filtrarla a una
    // pantalla y a los respaldos.
    ErrorEvent::anotar(
        new RuntimeException('API key not valid: AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123'),
        [], null, null, null,
    );

    $mensaje = (string) ErrorEvent::query()->value('message');

    expect($mensaje)->toContain('***')->and($mensaje)->not->toContain('AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123');
});

it('la pantalla enseña los errores agrupados', function (): void {
    ErrorEvent::anotar(new RuntimeException('se rompió algo'), ['x.php:9'], null, $this->primera->id, null);

    $this->actingAs($this->super)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('se rompió algo');
});

// ------------------------------------------------------------------------- La salud

it('una empresa desactivada cuenta como bloqueada', function (): void {
    // Mismo criterio que la pantalla de suspensión: si discrepara, el panel diría que todo va bien
    // mientras el cliente ve la puerta cerrada.
    Company::query()->whereKey($this->segunda->id)->update(['is_active' => false]);

    expect(app(PlatformHealthService::class)->calcular()['bloqueadas'])->toBe(1);
});

it('enseña el estado de los servicios externos', function (): void {
    $this->actingAs($this->super)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('Servicios externos')
        ->assertSee('Inteligencia Artificial')
        ->assertSee('WhatsApp');
});
