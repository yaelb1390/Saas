<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\ErrorEvent;
use App\Modules\Core\Services\CompanyService;
use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * Un mismo error que afecta a varias empresas.
 *
 * Antes cada error era «de una empresa»: una sola columna que cada repetición pisaba con la de la última
 * ocurrencia. Un fallo de Evolution que afectaba a doce empresas se veía como «148 veces» sin poder decir a
 * quiénes, y si la última vez ocurría en consola —sin empresa— hasta la última conocida se perdía.
 *
 * Lo que se cubre aquí es el contexto de cada empresa y de cada usuario: que se mantiene, que se cuenta
 * bien, y que lo que no es de ninguna empresa no se le atribuye a una por error.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    app(CurrentCompany::class)->forget();

    $this->abc = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa ABC'));
    $this->xyz = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa XYZ'));
    $this->def = app(CompanyService::class)->create(new CreateCompanyData(name: 'Empresa DEF'));

    $this->usuario = fn (object $empresa, string $nombre): User => User::create([
        'company_id' => $empresa->id, 'name' => $nombre,
        'email' => strtolower($nombre).'@monitor.test', 'password' => 'secret-password',
    ]);

    // El mismo cierre lanza SIEMPRE el error, así que todas las ocurrencias tienen el mismo origen y
    // solo cambian por el mensaje (o por lo que cada prueba quiera variar).
    $this->lanzar = fn (string $mensaje): Throwable => new RuntimeException($mensaje);

    $this->fallo = function (?int $empresa = null, ?int $usuario = null, string $mensaje = 'Connection timeout en Evolution API', ?string $url = 'http://localhost/webhooks/evolution'): void {
        ErrorEvent::anotar(($this->lanzar)($mensaje), ['F.php:1'], $url, $empresa, $usuario);
    };
});

// ------------------------------------------------------------------ Varias empresas, un solo error

it('un mismo error afecta a varias empresas y es UN grupo', function (): void {
    ($this->fallo)($this->abc->id);
    ($this->fallo)($this->xyz->id);
    ($this->fallo)($this->def->id);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->hits)->toBe(3)
        ->and($grupo->companies_count)->toBe(3)
        ->and($grupo->companies()->count())->toBe(3)
        ->and($grupo->status)->toBe('active')
        ->and($grupo->fingerprint_version)->toBe(2);
});

it('las ocurrencias por empresa se mantienen', function (): void {
    // «Empresa ABC 47 ocurrencias, Empresa XYZ 31…»: aquí en pequeño, A×3 y B×2.
    foreach ([$this->abc, $this->abc, $this->abc, $this->xyz, $this->xyz] as $empresa) {
        ($this->fallo)($empresa->id);
    }

    $grupo = ErrorEvent::query()->sole();
    $porEmpresa = $grupo->companies()->pluck('hits', 'company_id')->all();

    expect($grupo->hits)->toBe(5)
        ->and($grupo->companies_count)->toBe(2)
        ->and($porEmpresa)->toBe([$this->abc->id => 3, $this->xyz->id => 2]);
});

it('lo que no es de ninguna empresa se cuenta aparte, sin atribuírselo a nadie', function (): void {
    ($this->fallo)($this->abc->id);
    ($this->fallo)($this->abc->id);
    ($this->fallo)(null);          // consola, plataforma, un visitante sin sesión

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->hits)->toBe(3)
        ->and($grupo->companies_count)->toBe(1)
        ->and($grupo->hitsSinEmpresa())->toBe(1);
});

it('una ocurrencia sin empresa no borra la última empresa que se conocía', function (): void {
    // Antes la última repetición pisaba la columna, y un NULL de consola dejaba el error «sin empresa».
    ($this->fallo)($this->abc->id);
    ($this->fallo)(null);

    expect(ErrorEvent::query()->sole()->company_id)->toBe($this->abc->id);
});

it('cada empresa guarda cuándo lo vio por primera y por última vez', function (): void {
    $this->travelTo(now()->setDate(2026, 9, 20)->setTime(18, 2));
    ($this->fallo)($this->abc->id);

    $this->travelTo(now()->setDate(2026, 9, 20)->setTime(18, 46));
    ($this->fallo)($this->abc->id);

    $fila = ErrorEvent::query()->sole()->companies()->sole();

    expect($fila->first_seen_at->format('H:i'))->toBe('18:02')
        ->and($fila->last_seen_at->format('H:i'))->toBe('18:46');
});

// ------------------------------------------------------------------ Usuarios afectados

it('cuenta los usuarios afectados y las veces de cada uno', function (): void {
    $ana = ($this->usuario)($this->abc, 'Ana');
    $luis = ($this->usuario)($this->xyz, 'Luis');

    // Ana pulsa dos veces el botón roto: dos ocurrencias, una persona afectada.
    ($this->fallo)($this->abc->id, $ana->id);
    ($this->fallo)($this->abc->id, $ana->id);
    ($this->fallo)($this->xyz->id, $luis->id);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->hits)->toBe(3)
        ->and($grupo->users_count)->toBe(2)
        ->and($grupo->users()->pluck('hits', 'user_id')->all())->toBe([$ana->id => 2, $luis->id => 1])
        // Cada usuario recuerda de qué empresa era.
        ->and($grupo->users()->where('user_id', $luis->id)->value('company_id'))->toBe($this->xyz->id);
});

it('un usuario sin empresa (el operador) cuenta como afectado pero no como empresa', function (): void {
    $operador = User::create([
        'company_id' => null, 'name' => 'Operador', 'email' => 'op@monitor.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    ($this->fallo)(null, $operador->id);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->users_count)->toBe(1)->and($grupo->companies_count)->toBe(0);
});

// ------------------------------------------------------------------ Estado

it('un error resuelto que vuelve a ocurrir se reactiva', function (): void {
    ($this->fallo)($this->abc->id);

    ErrorEvent::query()->sole()->forceFill([
        'status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => 99,
    ])->save();

    ($this->fallo)($this->abc->id);

    $grupo = ErrorEvent::query()->sole();

    // Alguien lo dio por arreglado y no lo estaba.
    expect($grupo->status)->toBe('active')
        ->and($grupo->resolved_at)->toBeNull()
        ->and($grupo->resolved_by)->toBeNull();
});

it('un error ignorado sigue contando pero no se reactiva', function (): void {
    ($this->fallo)($this->abc->id);
    ErrorEvent::query()->sole()->forceFill(['status' => 'ignored'])->save();

    ($this->fallo)($this->abc->id);

    $grupo = ErrorEvent::query()->sole();

    expect($grupo->status)->toBe('ignored')->and($grupo->hits)->toBe(2);
});

// ------------------------------------------------------------------ Errores que antes se confundían

it('los errores de base de datos distintos ya no caen todos en un grupo', function (): void {
    // Toda QueryException nace en el mismo fichero de la librería: antes, la huella (clase + fichero + línea)
    // era la misma para CUALQUIER consulta que fallara, y solo se veía el mensaje de la última.
    $consulta = fn (string $sql): Throwable => new QueryException(
        'pgsql', $sql, [1], new PDOException('SQLSTATE[42P01]: Undefined table', 42501),
    );

    foreach (['select * from clientes where id = ?', 'select * from pedidos where id = ?'] as $sql) {
        ErrorEvent::anotar($consulta($sql), [], null, $this->abc->id, null);
    }

    // Y la misma consulta con otro valor sí es el mismo error.
    ErrorEvent::anotar($consulta('select * from clientes where id = ?'), [], null, $this->abc->id, null);

    expect(ErrorEvent::query()->count())->toBe(2)
        ->and(ErrorEvent::query()->pluck('service')->unique()->all())->toBe(['database']);
});

it('el servicio se deduce de la dirección a la que se llamó', function (string $direccion, string $servicio): void {
    config(['evolution.base_url' => 'https://evolution.ejemplo.test']);

    ($this->fallo)($this->abc->id, null, "cURL error 28: Operation timed out for {$direccion}");

    expect(ErrorEvent::query()->sole()->service)->toBe($servicio);
})->with([
    'Polar' => ['https://api.polar.sh/v1/subscriptions/123', 'polar'],
    'Polar (sandbox)' => ['https://sandbox-api.polar.sh/v1/checkouts/', 'polar'],
    'Zernio' => ['https://api.zernio.com/v1/accounts', 'zernio'],
    'Gemini' => ['https://generativelanguage.googleapis.com/v1beta/models', 'ai'],
    'OpenAI' => ['https://api.openai.com/v1/chat/completions', 'ai'],
    'Evolution (la de esta instalación)' => ['https://evolution.ejemplo.test/instance/connectionState/x', 'evolution'],
    'una dirección desconocida' => ['https://otra.cosa.test/x', 'app'],
]);

it('el mismo texto contra dos servicios distintos son dos errores', function (): void {
    ($this->fallo)($this->abc->id, null, 'cURL error 28: Operation timed out for https://api.polar.sh/v1/x');
    ($this->fallo)($this->abc->id, null, 'cURL error 28: Operation timed out for https://api.zernio.com/v1/x');

    expect(ErrorEvent::query()->count())->toBe(2);
});

// ------------------------------------------------------------------ Lo que se guarda

it('la dirección se guarda sin el secreto', function (): void {
    // El webhook de Evolution acepta su secreto por `?secret=`: la excepción de esa petición guardaba la
    // dirección completa, secreto incluido.
    ($this->fallo)($this->abc->id, null, 'falló', 'http://localhost/webhooks/evolution?secret=abc123-el-secreto&instance=colmado');

    $url = (string) ErrorEvent::query()->sole()->url;

    expect($url)->toBe('http://localhost/webhooks/evolution?secret=***&instance=***')
        ->and($url)->not->toContain('abc123-el-secreto');
});

it('el mensaje de una consulta no guarda los valores ni los datos de la conexión', function (): void {
    $e = new QueryException(
        'pgsql',
        'update users set password = ? where email = ?',
        ['hunter2-la-clave', 'ana@empresa.test'],
        new PDOException('SQLSTATE[23505]: Unique violation DETAIL: Key (email)=(ana@empresa.test) already exists.', 23505),
    );

    ErrorEvent::anotar($e, [], null, $this->abc->id, null);

    $mensaje = (string) ErrorEvent::query()->sole()->message;

    expect($mensaje)->not->toContain('hunter2-la-clave')
        ->and($mensaje)->not->toContain('ana@empresa.test')
        ->and($mensaje)->toContain('update users set password = ?');
});

it('una clave dentro del mensaje se guarda tachada, también en el modo nuevo', function (): void {
    ($this->fallo)($this->abc->id, null, 'API key not valid: AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123');

    $mensaje = (string) ErrorEvent::query()->sole()->message;

    expect($mensaje)->toContain('***')->and($mensaje)->not->toContain('AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123');
});

// ------------------------------------------------------------------ El tope

it('un bucle de errores no se convierte en un bucle de escrituras', function (): void {
    // Cada mensaje distinto es un grupo nuevo: sin tope serían 60 filas nuevas de golpe, y la base de datos
    // suele ser justo lo que ya está fallando.
    foreach (range(1, 60) as $i) {
        ($this->fallo)($this->abc->id, null, "Fallo distinto número {$i} de este bucle");
    }

    // Los mensajes solo se diferencian en un número, que se normaliza: serían UN grupo. Se usan letras.
    expect(ErrorEvent::query()->count())->toBeLessThanOrEqual(1);

    foreach (range('a', 'z') as $i => $letra) {
        foreach (['x', 'y'] as $sufijo) {
            ($this->fallo)($this->abc->id, null, "Fallo del componente {$letra}{$sufijo} de este bucle");
        }
    }

    // 52 mensajes distintos, pero solo se anotan 30 por minuto y por proceso (contando los 60 de antes, que
    // eran uno solo pero cada llamada gasta cupo).
    expect(ErrorEvent::query()->count())->toBeLessThanOrEqual(30);
});

// ------------------------------------------------------------------ El manejador de errores, de punta a punta

it('el manejador de errores guarda lo que se reporta', function (): void {
    report(new RuntimeException('se rompió algo en el manejador'));

    $grupo = ErrorEvent::query()->where('message', 'like', '%se rompió algo en el manejador%')->sole();

    expect($grupo->class)->toBe(RuntimeException::class)
        ->and($grupo->fingerprint_version)->toBe(2);
});

it('reportar dos veces la misma excepción cuenta una sola', function (): void {
    // Hay unos veinte `report($e)` explícitos en el código: si la excepción se relanza, el manejador la
    // vuelve a reportar y contaba dos veces.
    $e = new RuntimeException('reportada dos veces');

    report($e);
    report($e);

    expect(ErrorEvent::query()->where('message', 'like', '%reportada dos veces%')->sole()->hits)->toBe(1);
});

it('un error del operador de la plataforma no se le atribuye a ninguna empresa', function (): void {
    // El middleware le fija «la primera empresa» al operador. Sin `TenantAttribution`, este error quedaba
    // como de la empresa ABC.
    $operador = User::create([
        'company_id' => $this->abc->id, 'name' => 'Operador', 'email' => 'op@monitor.test',
        'password' => 'secret-password', 'is_super_admin' => true,
    ]);

    // Sin `debug`: en ese modo Laravel pinta la página de error con el código fuente de cada marco de la
    // traza, y en Docker sobre Windows leer esos ficheros lleva más de dos minutos. No aporta nada a lo
    // que se prueba aquí, que es qué se anota cuando la petición falla.
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__monitor/falla', fn () => throw new RuntimeException('falla de la ruta del operador'))
        ->name('monitor.prueba.falla');

    $this->actingAs($operador)->get('/__monitor/falla?secret=abc123')->assertStatus(500);

    $grupo = ErrorEvent::query()->where('message', 'like', '%falla de la ruta del operador%')->sole();

    expect($grupo->companies_count)->toBe(0)
        ->and($grupo->users_count)->toBe(1)
        ->and($grupo->route_name)->toBe('monitor.prueba.falla')
        ->and((string) $grupo->url)->not->toContain('abc123');
});

it('un error de un usuario de empresa se le atribuye a su empresa', function (): void {
    $dueno = ($this->usuario)($this->xyz, 'Dueno');

    config(['app.debug' => false]); // ver la prueba anterior

    Route::middleware('web')->get('/__monitor/falla-tenant', fn () => throw new RuntimeException('falla de la ruta del tenant'));

    $this->actingAs($dueno)->get('/__monitor/falla-tenant')->assertStatus(500);

    $grupo = ErrorEvent::query()->where('message', 'like', '%falla de la ruta del tenant%')->sole();

    expect($grupo->companies()->pluck('company_id')->all())->toBe([$this->xyz->id])
        ->and($grupo->users()->pluck('user_id')->all())->toBe([$dueno->id]);
});
