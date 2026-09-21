<?php

declare(strict_types=1);

use App\Modules\Core\Monitoring\Errors\ErrorFingerprint;
use App\Modules\Core\Monitoring\Errors\ExceptionSnapshot;
use App\Modules\Core\Monitoring\Errors\MessageNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\View\ViewException;

/*
 * La huella de un error: lo que decide que dos fallos sean «el mismo».
 *
 * Es la decisión más delicada del agrupado y por eso se prueba a fondo y sin base de datos: si agrupa de
 * MÁS, dos problemas distintos se esconden uno dentro del otro (y el operador mira uno mientras el otro
 * sigue roto); si agrupa de MENOS, un fallo que ocurre mil veces con mil ids llena la pantalla de mil grupos.
 * Los dos fallos se ven perfectos hasta que alguien mira con atención, así que se prueban los dos lados.
 *
 * Los cierres auxiliares viven dentro de cada prueba y no como funciones globales: las funciones de un
 * fichero de pruebas son globales para toda la suite, y una colisión de nombres la tumba entera.
 */

/** Dos «lugares» distintos del código propio desde donde lanzar el mismo error. */
$lugares = function (): array {
    $uno = new class
    {
        public function falla(string $mensaje): Throwable
        {
            return new RuntimeException($mensaje);
        }
    };

    $otro = new class
    {
        public function tambienFalla(string $mensaje): Throwable
        {
            return new RuntimeException($mensaje);
        }
    };

    return [$uno, $otro];
};

$base = dirname(__DIR__, 4);

$huella = static fn (Throwable $e, string $servicio = 'app', ?string $ruta = null, ?string $raiz = null): string => ErrorFingerprint::make(
    ExceptionSnapshot::from($e, $raiz ?? $base),
    $servicio,
    $ruta,
);

// ------------------------------------------------------------------------------- Agrupa lo equivalente

it('agrupa el mismo error aunque cambien los datos de esa vez', function (string $uno, string $otro) use ($lugares, $huella): void {
    [$lugar] = $lugares();

    expect($huella($lugar->falla($uno)))->toBe($huella($lugar->falla($otro)));
})->with([
    'un id en la ruta' => ['Timeout calling API /12345', 'Timeout calling API /67890'],
    'una dirección con ids' => [
        'Timeout calling https://api.ejemplo.test/v1/orders/123/items',
        'Timeout calling https://api.ejemplo.test/v1/orders/98765/items',
    ],
    'otro UUID' => [
        'Order 8f14e45f-ceea-467a-9575-1d4a5b1a3c2e not found',
        'Order 1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed not found',
    ],
    'otro correo' => ['User ana@empresa.test not found', 'User luis@otra.example.org not found'],
    'otra IP' => ['Connection from 10.0.0.5 refused', 'Connection from 192.168.1.20 refused'],
    'otra fecha y hora' => ['Failed at 2026-09-20 10:15:33', 'Failed at 2027-01-02 08:01:02'],
    'otro dato entre comillas' => ["Customer 'Ana Pérez' not found", "Customer 'Luis Gómez' not found"],
    'otro número suelto' => ['Stock is 7 but 12 were requested', 'Stock is 3 but 90 were requested'],
    'otra consulta en la query de la dirección' => [
        'GET https://api.ejemplo.test/v1/items?page=2&token=abc failed',
        'GET https://api.ejemplo.test/v1/items?page=9&token=xyz failed',
    ],
]);

it('la misma huella aunque el código se mueva de línea', function () use ($huella): void {
    // Cada despliegue que toca ese fichero cambia sus números de línea. La huella anterior llevaba la
    // línea, así que partía el mismo fallo en dos tras cada edición.
    //
    // El mismo método lanza el error desde DOS líneas distintas: es lo que pasa con el mismo código
    // antes y después de que alguien añada unas líneas encima.
    $lugar = new class
    {
        public function falla(bool $masAbajo): Throwable
        {
            if ($masAbajo) {
                // Unas líneas más abajo...
                return new RuntimeException('algo falló');
            }

            return new RuntimeException('algo falló');
        }
    };

    $antes = $lugar->falla(false);
    $despues = $lugar->falla(true);

    expect($antes->getLine())->not->toBe($despues->getLine())
        ->and($huella($antes))->toBe($huella($despues));
});

it('la sentencia con otros valores es el mismo error de base de datos', function () use ($huella): void {
    $consulta = static fn (string $correo): QueryException => new QueryException(
        'pgsql',
        'select * from users where email = ? and active = ?',
        [$correo, true],
        new PDOException('SQLSTATE[23505]: Unique violation: duplicate key value', 23505),
    );

    expect($huella($consulta('ana@x.test')))->toBe($huella($consulta('luis@y.test')));
});

// ------------------------------------------------------------------------------- Distingue lo distinto

it('distingue errores que se parecen pero no son el mismo', function (string $uno, string $otro) use ($lugares, $huella): void {
    [$lugar] = $lugares();

    expect($huella($lugar->falla($uno)))->not->toBe($huella($lugar->falla($otro)));
})->with([
    // Los números ESTÁN en el texto porque son el dato: un tiempo agotado y un nombre que no resuelve
    // son problemas distintos aunque el resto de la frase coincida.
    'cURL 28 frente a cURL 6' => ['cURL error 28: Operation timed out', 'cURL error 6: Could not resolve host'],
    'HTTP 401 frente a HTTP 500' => [
        'HTTP request returned status code 401',
        'HTTP request returned status code 500',
    ],
    'otro host' => [
        'Timeout calling https://api.polar.test/v1/products',
        'Timeout calling https://api.zernio.test/v1/products',
    ],
    'otra columna' => ["Column 'name' not found", "Column 'price' not found"],
    'otro mensaje' => ['Connection refused', 'Permission denied'],
]);

it('distingue por la clase de la excepción', function () use ($huella): void {
    // El MISMO método, el mismo mensaje: lo único que cambia es la clase.
    $lugar = new class
    {
        public function lanza(bool $logica): Throwable
        {
            return $logica ? new LogicException('falló') : new RuntimeException('falló');
        }
    };

    expect($huella($lugar->lanza(false)))->not->toBe($huella($lugar->lanza(true)));
});

it('distingue por el método de origen', function () use ($lugares, $huella): void {
    [$uno, $otro] = $lugares();

    expect($huella($uno->falla('igual')))->not->toBe($huella($otro->tambienFalla('igual')));
});

it('distingue por el servicio', function () use ($lugares, $huella): void {
    [$lugar] = $lugares();
    $e = $lugar->falla('Connection timed out');

    expect($huella($e, 'evolution'))->not->toBe($huella($e, 'polar'));
});

it('distingue los errores de base de datos por su SQLSTATE y por la sentencia', function () use ($huella): void {
    $consulta = static fn (string $sql, int $estado): QueryException => new QueryException(
        'pgsql', $sql, [1], new PDOException('SQLSTATE: fallo', $estado),
    );

    $base = $consulta('select * from users where id = ?', 23505);

    // Antes TODAS las QueryException tenían la misma huella: nacen en el mismo fichero de la librería.
    expect($huella($base))->not->toBe($huella($consulta('select * from users where id = ?', 23503)))
        ->and($huella($base))->not->toBe($huella($consulta('select * from orders where id = ?', 23505)));
});

// ------------------------------------------------------------------------------- La ruta y el origen débil

it('con origen propio, la ruta de la petición no parte el mismo error', function () use ($lugares, $huella): void {
    // El mismo fallo de Evolution en el webhook, en un job y en el panel es UN problema.
    [$lugar] = $lugares();
    $e = $lugar->falla('Connection refused');

    expect($huella($e, 'app', 'webhooks.evolution'))->toBe($huella($e, 'app', 'panel.chats'));
});

it('sin ningún código propio, la ruta es lo único que distingue', function () use ($lugares, $huella): void {
    // Una raíz que no contiene este fichero deja el error sin marcos propios: solo hay código de terceros.
    [$lugar] = $lugares();
    $e = $lugar->falla('Connection refused');

    $aqui = $huella($e, 'app', 'panel.chats', '/una/raiz/que/no/es');
    $alli = $huella($e, 'app', 'panel.ventas', '/una/raiz/que/no/es');

    expect(ExceptionSnapshot::from($e, '/una/raiz/que/no/es')->weakOrigin)->toBeTrue()
        ->and($aqui)->not->toBe($alli)
        ->and($huella($e, 'app', 'panel.chats', '/una/raiz/que/no/es'))->toBe($aqui);
});

it('una raíz parecida no cuenta como la aplicación', function () use ($lugares, $base): void {
    // `/var/www/html2` no es `/var/www/html`.
    [$lugar] = $lugares();

    expect(ExceptionSnapshot::from($lugar->falla('x'), $base.'2')->weakOrigin)->toBeTrue();
});

// ------------------------------------------------------------------------------- El formato de la huella

it('mide lo mismo que las antiguas pero no puede coincidir con ninguna', function () use ($lugares, $huella): void {
    [$lugar] = $lugares();

    $nueva = $huella($lugar->falla('algo falló'));

    // La columna guarda 40 caracteres. Las antiguas eran `sha1` (40 hexadecimales, sin prefijo): el
    // prefijo `v2_` garantiza que una huella nueva nunca choca con un grupo antiguo.
    expect($nueva)->toHaveLength(40)
        ->and($nueva)->toStartWith('v2_')
        ->and($nueva)->not->toMatch('/^[0-9a-f]{40}$/');
});

it('es determinista', function () use ($lugares, $huella): void {
    [$lugar] = $lugares();

    expect($huella($lugar->falla('igual')))->toBe($huella($lugar->falla('igual')));
});

// ------------------------------------------------------------------------------- Lo que se guarda

it('el origen no lleva número de línea ni el nombre de una función anónima con línea', function () use ($lugares, $base): void {
    [$lugar] = $lugares();

    $origen = ExceptionSnapshot::from($lugar->falla('x'), $base)->origin;

    // `{closure:Clase::método():42}` (PHP 8.4) lleva la línea dentro; `{closure}` (8.3) no. Se normalizan.
    expect($origen)->toStartWith('tests/Unit/Core/Monitoring/ErrorFingerprintTest.php@')
        ->and($origen)->not->toMatch('/:\d+\)?\}?$/')
        ->and($origen)->not->toContain('{closure:');
});

it('los valores de una consulta no llegan al texto que se guarda', function () use ($base): void {
    $e = new QueryException(
        'pgsql',
        'update users set password = ? where email = ?',
        ['hunter2-la-clave', 'ana@empresa.test'],
        new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key DETAIL: Key (email)=(ana@empresa.test) already exists.', 23505),
    );

    $texto = ExceptionSnapshot::from($e, $base)->sampleMessage;

    // El mensaje de la excepción de Laravel SÍ lleva los valores (`Str::replaceArray`).
    expect($e->getMessage())->toContain('hunter2-la-clave')
        ->and($texto)->not->toContain('hunter2-la-clave')
        ->and($texto)->not->toContain('ana@empresa.test')
        ->and($texto)->not->toContain('Connection:')
        ->and($texto)->toContain('update users set password = ?');
});

it('una vista de Blade que falla se agrupa por el error de dentro', function () use ($lugares, $base): void {
    [$lugar] = $lugares();
    $interno = $lugar->falla('Undefined variable $cliente');
    $vista = new ViewException('Undefined variable $cliente (View: /storage/x.blade.php)', 0, 1, '', 0, $interno);

    expect(ExceptionSnapshot::from($vista, $base)->class)->toBe(RuntimeException::class);
});

// ------------------------------------------------------------------------------- El normalizador

it('conserva lo que distingue un fallo y quita lo que es de esa vez', function (): void {
    $texto = MessageNormalizer::normalize(
        "SQLSTATE[23505]: Unique violation on users_email_unique for 'ana@x.test' at 2026-09-20 10:15:33 (id 4711)",
    );

    expect($texto)->toContain('SQLSTATE[23505]')
        ->and($texto)->toContain('{ts}')
        ->and($texto)->not->toContain('ana@x.test')
        ->and($texto)->not->toContain('4711');
});

it('no guarda credenciales en la huella', function (): void {
    $texto = MessageNormalizer::normalize('API key not valid: AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123');

    expect($texto)->not->toContain('AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123');
});

it('recorta los mensajes larguísimos', function (): void {
    expect(mb_strlen(MessageNormalizer::normalize(str_repeat('error muy largo ', 500))))->toBeLessThanOrEqual(200);
});

it('una sentencia SQL pierde sus valores y sus listas', function (): void {
    $sql = MessageNormalizer::normalizeSql("SELECT * FROM users WHERE id IN (1, 2, 3) AND name = 'Ana' AND age > 30 AND x = \$1");

    expect($sql)->toBe('select * from users where id in (?) and name = ? and age > ? and x = ?');
});

it('las inserciones múltiples son una sola sentencia', function (): void {
    $dos = MessageNormalizer::normalizeSql("insert into t (a, b) values ('x', 1), ('y', 2)");
    $cuatro = MessageNormalizer::normalizeSql("insert into t (a, b) values ('x', 1), ('y', 2), ('z', 3), ('w', 4)");

    expect($dos)->toBe($cuatro);
});
