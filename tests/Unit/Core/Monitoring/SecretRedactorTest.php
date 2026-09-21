<?php

declare(strict_types=1);

use App\Modules\Core\Support\SecretRedactor;

/*
 * Tachar credenciales antes de guardarlas o enseñarlas.
 *
 * Se prueba por los dos lados que importan: que NO deje pasar una clave (lo grave: se filtraría a una
 * pantalla y a todas las copias de seguridad) y que NO destroce texto normal (lo molesto: un mensaje
 * de error que ya no se entiende).
 */

it('tacha las claves de proveedores por su forma', function (string $secreto): void {
    $texto = SecretRedactor::redact("La llamada falló con {$secreto} y no hubo más");

    expect($texto)->not->toContain($secreto)->and($texto)->toContain('***');
})->with([
    'Google' => ['AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123'],
    'OpenAI' => ['sk-proj-abcdefghijklmnopqrstuv'],
    // Cortos a propósito: lo bastante largos para la expresión (8 o más tras el prefijo) y lo bastante
    // distintos de una clave real para que un escáner de secretos del repositorio no los confunda con una.
    'Brevo' => ['xkeysib-abcdef012345'],
    'Polar' => ['polar_oat_ejemplo1234'],
    'un Bearer' => ['Bearer abcdefghijklmnopqrstuvwxyz'],
    'un JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnopqrstuvwxyz012345'],
    'una clave de AWS' => ['AKIAIOSFODNN7EXAMPLE'],
]);

it('tacha la contraseña dentro de una dirección', function (): void {
    $texto = SecretRedactor::redact('could not connect to postgres://admin:s3cr3t-clave@db.ejemplo.test:5432/app');

    expect($texto)->not->toContain('s3cr3t-clave')
        ->and($texto)->toContain('db.ejemplo.test');
});

it('tacha lo que sigue a password, token, secret o api_key', function (string $fragmento, string $secreto): void {
    $texto = SecretRedactor::redact("Falló: {$fragmento} en la petición");

    expect($texto)->not->toContain($secreto)->and($texto)->toContain('***');
})->with([
    'con igual' => ['password=hunter2-la-clave', 'hunter2-la-clave'],
    'con dos puntos' => ['token: abcdef123456', 'abcdef123456'],
    'en JSON' => ['{"api_key":"abcdef123456"}', 'abcdef123456'],
    'con comillas simples' => ["secret = 'abcdef123456'", 'abcdef123456'],
    'en una dirección' => ['https://x.test/hook?signature=abcdef123456&a=1', 'abcdef123456'],
]);

it('no destroza un mensaje normal', function (string $mensaje): void {
    expect(SecretRedactor::redact($mensaje))->toBe($mensaje);
})->with([
    'una frase' => ['No se pudo abrir el cobro de la suscripción'],
    'sin dos puntos' => ['password authentication failed for user "postgres"'],
    'una palabra que solo contiene la clave' => ['El tokenizer devolvió un resultado vacío'],
    'un texto vacío' => [''],
]);

it('no tacha dos veces lo que ya está tachado', function (): void {
    expect(SecretRedactor::redact('authorization: ***'))->toBe('authorization: ***');
});

it('tacha por el NOMBRE de la clave en un detalle, sea cual sea el valor', function (): void {
    $limpio = SecretRedactor::redactArray([
        'password' => 'x',
        'apiKey' => 'una-clave-cualquiera',
        'client_secret' => ['anidado' => 'valor'],
        'Authorization' => 'Basic dXNlcjpwYXNz',
        'access_token' => 'abc',
        'cookie' => 'sesion=1',
        'estado' => 500,
        'respuesta' => 'todo bien',
    ]);

    expect($limpio['password'])->toBe('***')
        ->and($limpio['apiKey'])->toBe('***')
        ->and($limpio['client_secret'])->toBe('***')
        ->and($limpio['Authorization'])->toBe('***')
        ->and($limpio['access_token'])->toBe('***')
        ->and($limpio['cookie'])->toBe('***')
        ->and($limpio['estado'])->toBe(500)
        ->and($limpio['respuesta'])->toBe('todo bien');
});

it('una clave que solo se parece a una sensible se deja', function (): void {
    $limpio = SecretRedactor::redactArray(['passenger' => 'Ana', 'tokenizer' => 'v2', 'secretaria' => 'Luisa']);

    expect($limpio)->toBe(['passenger' => 'Ana', 'tokenizer' => 'v2', 'secretaria' => 'Luisa']);
});

it('tacha dentro de listas anidadas y recorta DESPUÉS de tachar', function (): void {
    // Recortar antes partía una clave por la mitad y la dejaba sin tachar.
    $secreto = 'AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123';
    $largo = str_repeat('x', 490).' '.$secreto;

    $limpio = SecretRedactor::redactArray(['nivel1' => ['nivel2' => ['texto' => $largo]]]);

    expect($limpio['nivel1']['nivel2']['texto'])->not->toContain('AIzaSyABCDEFGHIJKLMNOPQRSTUVWXYZ123')
        ->and(mb_strlen($limpio['nivel1']['nivel2']['texto']))->toBeLessThanOrEqual(500);
});

it('una dirección pierde el usuario, el fragmento y los valores de la consulta', function (): void {
    $url = SecretRedactor::sanitizeUrl('https://admin:clave@app.ejemplo.test:8443/webhooks/evolution?secret=abc123&instance=colmado#seccion');

    expect($url)->toBe('https://app.ejemplo.test:8443/webhooks/evolution?secret=***&instance=***')
        ->and($url)->not->toContain('abc123')
        ->and($url)->not->toContain('colmado')
        ->and($url)->not->toContain('admin');
});

it('una dirección sin consulta queda como estaba', function (): void {
    expect(SecretRedactor::sanitizeUrl('http://localhost/panel/ventas'))->toBe('http://localhost/panel/ventas')
        ->and(SecretRedactor::sanitizeUrl(null))->toBeNull()
        ->and(SecretRedactor::sanitizeUrl(''))->toBe('');
});

it('una dirección ilegible se corta en la consulta', function (): void {
    expect(SecretRedactor::sanitizeUrl('http:///nada?secret=abc123'))->not->toContain('abc123');
});
