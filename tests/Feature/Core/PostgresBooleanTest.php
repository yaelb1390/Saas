<?php

declare(strict_types=1);

use App\Modules\Core\Database\PostgresConnection;
use Illuminate\Database\Connection;

/*
 * Que se puedan guardar las casillas de verificación en PostgreSQL.
 *
 * No es un detalle de tipos: mientras estuvo roto no se podía marcar «Controla stock», ni «Activo»,
 * ni «Hoy no hay». La pantalla devolvía un 500 y el usuario solo veía que su cambio no se guardaba.
 *
 *     UPDATE products SET track_stock = 1 ...
 *     ERROR: column "track_stock" is of type boolean but expression is of type integer
 *
 * Laravel convierte los booleanos en enteros porque MySQL y SQLite los guardan así; con
 * `DB_EMULATE_PREPARES=true` —que este proyecto necesita para el pooler de Supabase— ese entero se
 * escribe DENTRO de la consulta, y ahí Postgres ya no lo acepta en una columna booleana.
 *
 * La prueba vive aquí y no contra una base Postgres de verdad porque la suite corre sobre SQLite:
 * lo que se fija es la TRADUCCIÓN, que es donde estaba el fallo. Lo demás lo comprobó una escritura
 * real contra Supabase con la emulación puesta.
 */

/** Una conexión de Postgres sin base detrás: solo se le pregunta cómo prepara los valores. */
function conexionPostgres(): PostgresConnection
{
    return new PostgresConnection(fn () => null, 'bmos', '', []);
}

it('los booleanos viajan como cadena, no como número', function (): void {
    /*
     * Cadena y no entero, y ahí está todo el arreglo.
     *
     * Entrecomillado, Postgres lo convierte a lo que pida la columna: «1» vale como verdadero en una
     * booleana y como uno en una numérica. Sin comillas, un 1 pelado es el número uno y una columna
     * booleana lo rechaza.
     */
    $preparados = conexionPostgres()->prepareBindings([true, false]);

    expect($preparados[0])->toBe('1')
        ->and($preparados[1])->toBe('0')
        // Que sea CADENA es la mitad que importa: como entero, PDO lo escribe sin comillas.
        ->and($preparados[0])->toBeString()
        ->and($preparados[1])->toBeString();
});

it('no toca nada que no sea booleano', function (): void {
    // El arreglo tiene que ser quirúrgico: si además convirtiera números o textos, rompería
    // consultas que hoy funcionan en todas partes.
    $preparados = conexionPostgres()->prepareBindings([1, 0, '1', 'hola', 3.5, null]);

    expect($preparados)->toBe([1, 0, '1', 'hola', 3.5, null]);
});

it('las fechas se siguen formateando como siempre', function (): void {
    // Se delega en el padre a propósito, para no quedarse atrás cuando el framework añada tipos
    // nuevos. Este test comprueba que esa delegación sigue en pie.
    $fecha = new DateTimeImmutable('2026-08-24 15:30:00');

    expect(conexionPostgres()->prepareBindings([$fecha])[0])->toBe('2026-08-24 15:30:00');
});

it('PostgreSQL usa esta conexión y no la de serie', function (): void {
    /*
     * Sin el resolvedor registrado, la clase de arriba no la usa nadie y el fallo vuelve sin que
     * ningún test se entere: los de arriba seguirían pasando porque prueban la clase directamente.
     */
    expect(Connection::getResolver('pgsql'))->not->toBeNull();

    $conexion = (Connection::getResolver('pgsql'))(fn () => null, 'bmos', '', []);

    expect($conexion)->toBeInstanceOf(PostgresConnection::class);
});
