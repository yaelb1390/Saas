<?php

declare(strict_types=1);

namespace App\Modules\Core\Database;

use Illuminate\Database\PostgresConnection as BaseConnection;

/**
 * La conexión de PostgreSQL, con los booleanos escritos de forma que Postgres los acepte.
 *
 * EL PROBLEMA, que era grave y silencioso: no se podía guardar NINGUNA casilla de verificación.
 * Marcar «Controla stock», «Activo» o «Hoy no hay» devolvía un error 500.
 *
 *     UPDATE products SET track_stock = 1 ...
 *     ERROR: column "track_stock" is of type boolean but expression is of type integer
 *
 * Sale de la suma de dos decisiones razonables por separado:
 *
 * 1. Laravel convierte los booleanos en enteros antes de mandarlos (ver Connection::prepareBindings).
 *    Lo hace porque MySQL y SQLite guardan los booleanos como 1 y 0.
 *
 * 2. Este proyecto usa `DB_EMULATE_PREPARES=true` —ver config/database.php—, que hacía falta porque
 *    el pooler de Supabase en modo transacción manda cada consulta a una conexión distinta y los
 *    prepared statements con nombre no sobreviven a ese salto.
 *
 * Por separado no pasa nada. Juntas sí: con la emulación, PDO ya no manda el valor aparte para que
 * el motor lo interprete, sino que lo escribe DENTRO de la consulta. Y ahí un `1` pelado deja de ser
 * «verdadero» para pasar a ser el número uno, que en una columna booleana Postgres rechaza.
 *
 * LA SOLUCIÓN: mandarlos como CADENA, `'1'` y `'0'`, en vez de como entero.
 *
 * Como cadena, PDO los escribe entrecomillados y Postgres los convierte a lo que pida la columna:
 * `'1'` vale como verdadero en una columna booleana y como uno en una numérica. Así sirve para las
 * dos, que es lo que hace que este arreglo no tenga que saber a qué columna va cada valor.
 *
 * Se corrige aquí y no quitando la emulación porque esa se puso para tapar un fallo real e
 * intermitente del pooler. Cambiar la fontanería de la conexión para arreglar un tipo de dato habría
 * sido cambiar un problema por otro, y el otro solo aparece bajo concurrencia —el peor momento—.
 */
final class PostgresConnection extends BaseConnection
{
    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int|string, mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $clave => $valor) {
            if (is_bool($valor)) {
                $bindings[$clave] = $valor ? '1' : '0';
            }
        }

        /*
         * El resto se lo deja al padre: fechas, enumeraciones y lo que vaya añadiendo el framework.
         *
         * Al pasar antes por aquí, los booleanos ya son cadenas, así que su rama de booleanos no
         * llega a ejecutarse y no vuelve a convertirlos en enteros.
         */
        return parent::prepareBindings($bindings);
    }
}
