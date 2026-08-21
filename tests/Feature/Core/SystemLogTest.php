<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Http\Controllers\MonitoringController;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
 * El registro del sistema.
 *
 * Existe porque había un agujero grande: HASTA AHORA NO QUEDABA RASTRO DE NINGÚN INICIO DE SESIÓN.
 * No se podía responder a «¿quién entró en la cuenta de este cliente?» ni ver que alguien lleva
 * doscientos intentos contra un correo.
 *
 * No duplica lo que ya hay: `audits` guarda quién cambió una fila y `error_events` las excepciones.
 * Esto guarda lo que no es ninguna de las dos cosas.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(CurrentCompany::class)->forget();
    SystemEvent::olvidarSiHayTabla();

    $this->company = app(CompanyService::class)->create(new CreateCompanyData(name: 'Colmado'));
    app(CurrentCompany::class)->set($this->company->id);

    $this->owner = withRole(User::create([
        'company_id' => $this->company->id, 'name' => 'Duena',
        'email' => 'duena@colmado.test', 'password' => 'secret-password',
    ]), 'owner');
});

// ------------------------------------------------------------------ Accesos

it('deja constancia de quién entra', function (): void {
    $this->post(route('login'), ['email' => 'duena@colmado.test', 'password' => 'secret-password']);

    $fila = SystemEvent::query()->where('type', 'auth.login')->first();

    expect($fila)->not->toBeNull()
        ->and($fila->user_id)->toBe($this->owner->id)
        ->and($fila->company_id)->toBe($this->company->id);
});

it('y de quién lo intenta sin conseguirlo', function (): void {
    // Es la pregunta que se hace cuando algo huele mal: ¿alguien está probando contra esta cuenta?
    $this->post(route('login'), ['email' => 'duena@colmado.test', 'password' => 'la-que-no-es']);

    $fila = SystemEvent::query()->where('type', 'auth.failed')->first();

    expect($fila)->not->toBeNull()
        ->and($fila->level)->toBe(SystemEvent::AVISO)
        ->and($fila->context['la_cuenta_existe'])->toBeTrue();
});

it('distingue un dedazo de alguien probando correos al azar', function (): void {
    // Equivocarse de contraseña y probar correos inventados son dos problemas distintos, y la
    // diferencia se ve en una sola columna.
    $this->post(route('login'), ['email' => 'noexiste@ninguna.test', 'password' => 'lo-que-sea']);

    expect(SystemEvent::query()->where('type', 'auth.failed')->first()->context['la_cuenta_existe'])
        ->toBeFalse();
});

it('NUNCA guarda la contraseña que se tecleó', function (): void {
    /*
     * El evento de Laravel trae las credenciales con la contraseña en claro. Guardarla sería peor
     * que no tener registro: quien abriera esta tabla vería la contraseña de todo el que se
     * equivocó de campo, y las contraseñas se repiten entre sitios.
     */
    $this->post(route('login'), ['email' => 'duena@colmado.test', 'password' => 'MiClaveSecreta123']);

    $todo = SystemEvent::query()->get()->toJson();

    expect($todo)->not->toContain('MiClaveSecreta123');
});

// ------------------------------------------------------- Lo que no puede romper

it('un fallo al registrar no tumba lo que estaba registrando', function (): void {
    /*
     * Esto se llama DENTRO del inicio de sesión. Si reventara al escribir, se llevaría por delante
     * el acceso: nadie podría entrar al sistema porque no se pudo anotar que entraba.
     *
     * Es el mismo hueco que ya tuvimos con la auditoría, y por eso se comprueba igual: sin la tabla.
     */
    Schema::drop('system_events');
    SystemEvent::olvidarSiHayTabla();

    $this->post(route('login'), ['email' => 'duena@colmado.test', 'password' => 'secret-password'])
        ->assertRedirect();

    expect(auth()->check())->toBeTrue();
});

it('tacha las claves que vengan en el detalle', function (): void {
    // Un fallo de una API trae la credencial dentro más veces de las que parece, y guardarla aquí
    // sería filtrarla a una pantalla y a todas las copias de seguridad.
    SystemEvent::registrar(
        type: 'integration.failed',
        message: 'Zernio rechazó la llamada',
        contexto: ['respuesta' => 'Invalid key sk_0123456789abcdef0123456789abcdef'],
    );

    $guardado = SystemEvent::query()->latest('id')->first()->context['respuesta'];

    expect($guardado)->toContain('***')->not->toContain('0123456789abcdef');
});

// ------------------------------------------------------- Acciones de plataforma

it('suspender una empresa queda registrado como grave', function (): void {
    // Es lo que contesta «¿por qué mis usuarios no pueden entrar?» seis meses después.
    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)
        ->post(route('platform.companies.toggle', $this->company))
        ->assertRedirect();

    $fila = SystemEvent::query()->where('type', 'platform.company_suspended')->first();

    expect($fila)->not->toBeNull()
        ->and($fila->level)->toBe(SystemEvent::GRAVE)
        ->and($fila->company_id)->toBe($this->company->id);
});

// ------------------------------------------------------------------ La pantalla

it('el registro se ve en Monitoreo, y solo lo ve el operador', function (): void {
    SystemEvent::registrar(type: 'auth.login', message: 'Alguien entró al sistema');

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op2@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('Registro del sistema')
        ->assertSee('Alguien entró al sistema');

    // Un dueño de empresa no pasa: el registro cruza empresas y lleva IPs y correos.
    $this->actingAs($this->owner)->get(route('platform.monitoring'))->assertForbidden();
});

it('el registro NO se filtra por la empresa activa del operador', function (): void {
    /*
     * La trampa de siempre en estas pantallas: un super admin SIEMPRE tiene una empresa activa, así
     * que una consulta con el ámbito puesto enseñaría solo las suyas haciéndolas pasar por las de
     * toda la plataforma, y sin dar error.
     */
    $otra = app(CompanyService::class)->create(new CreateCompanyData(name: 'Ferretería'));

    SystemEvent::registrar(type: 'auth.login', message: 'Entró en la otra', companyId: (int) $otra->id);

    app(CurrentCompany::class)->set($this->company->id);

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op3@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->get(route('platform.monitoring'))
        ->assertOk()
        ->assertSee('Entró en la otra');
});

it('se puede buscar por texto, que es como se persigue un correo', function (): void {
    SystemEvent::registrar(type: 'auth.failed', message: 'Intento de acceso fallido con ladron@mal.test');
    SystemEvent::registrar(type: 'auth.login', message: 'Duena entró al sistema');

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op4@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->get(route('platform.monitoring', ['busca' => 'ladron']))
        ->assertOk()
        ->assertSee('ladron@mal.test')
        ->assertDontSee('Duena entró al sistema');
});

// ------------------------------------------------------------------ Limpieza

it('lo de más de un año se borra y lo de ayer no', function (): void {
    SystemEvent::registrar(type: 'auth.login', message: 'Viejo');
    SystemEvent::query()->update(['created_at' => now()->subYears(2)]);

    SystemEvent::registrar(type: 'auth.login', message: 'Reciente');

    $superadmin = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op5@bmos.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    $this->actingAs($superadmin)->post(route('platform.monitoring.clean'))->assertRedirect();

    expect(SystemEvent::query()->pluck('message')->all())->toBe(['Reciente']);
});

// ------------------------------------------------------- Tareas programadas

/*
 * En Vercel no hay cron propio: las tareas las dispara un servicio externo llamando a estas
 * direcciones. Si dejara de llamarlas —se cambió el secreto, se borró el cron, cambió el dominio—
 * las pruebas no se purgarían y los avisos no saldrían, y NADIE SE ENTERARÍA: no hay error, no pasa
 * nada. Ver que la última ejecución fue hace tres semanas es la única forma de detectarlo.
 */

it('una tarea programada deja constancia de que corrió', function (): void {
    config(['services.cron.secret' => 'secreto-de-prueba']);

    $this->withHeaders(['Authorization' => 'Bearer secreto-de-prueba'])
        ->get(route('tasks.purge-trials'))
        ->assertOk();

    expect(SystemEvent::query()->where('type', 'task.run')->count())->toBe(1);
});

it('y quien la llama sin el secreto también', function (): void {
    // Alguien probando la puerta del cron es una señal, no un ruido.
    config(['services.cron.secret' => 'secreto-de-prueba']);

    $this->withHeaders(['Authorization' => 'Bearer el-que-no-es'])
        ->get(route('tasks.purge-trials'))
        ->assertForbidden();

    expect(SystemEvent::query()->where('type', 'task.rejected')->count())->toBe(1);
});

// ------------------------------------------------------------ Webhooks rechazados

it('un webhook con el secreto cambiado queda registrado', function (): void {
    // Se registran los RECHAZADOS y no los que llegan bien: uno con firma mala es alguien probando
    // la puerta; registrar los correctos sería una fila por cada mensaje de cada cliente.
    config(['evolution.webhook_secret' => 'el-bueno']);

    $this->postJson(route('webhooks.evolution').'?secret=el-malo', ['instance' => 'colmado'])
        ->assertUnauthorized();

    $fila = SystemEvent::query()->where('type', 'webhook.rejected')->first();

    expect($fila)->not->toBeNull()->and($fila->level)->toBe(SystemEvent::AVISO);
});

// --------------------------------------------- El filtro no puede ofrecer humo

it('toda familia del filtro la escribe alguien de verdad', function (): void {
    /*
     * El filtro ofrecía «Tareas programadas» y «Webhooks» cuando NADIE escribía esos tipos: dos
     * opciones que no podían devolver nada nunca. Este test recorre el código y lo comprueba, así
     * que añadir una familia sin instrumentarla vuelve a fallar aquí.
     */
    $familias = (new ReflectionClass(MonitoringController::class))
        ->getConstant('FAMILIAS');

    $codigo = '';

    foreach (['app/Modules', 'app/Http'] as $carpeta) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($carpeta))) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $codigo .= file_get_contents($f->getPathname());
            }
        }
    }

    $sinEscribir = [];

    foreach (array_keys($familias) as $familia) {
        if (! str_contains($codigo, "'{$familia}.")) {
            $sinEscribir[] = $familia;
        }
    }

    expect($sinEscribir)->toBe([], 'El filtro ofrece familias que nadie escribe: '.implode(', ', $sinEscribir));
});
