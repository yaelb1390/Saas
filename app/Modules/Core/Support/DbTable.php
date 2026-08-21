<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ¿Existe ya esta tabla?
 *
 * Existe por una condición de este proyecto que no se puede olvidar: **las migraciones se aplican a
 * mano y el despliegue NO las corre**. Entre que el código sale y alguien aplica la migración pasan
 * minutos, horas o un fin de semana, y en ese hueco el código nuevo se encuentra una base vieja.
 *
 * Lo que pasa si no se comprueba no es «la función nueva todavía no se ve»: es que la PANTALLA ENTERA
 * devuelve un 500. Ocurrió de verdad —la pantalla de Redes sociales se cayó en producción porque
 * consultaba una tabla que allí aún no existía— y ese es el motivo de que esto esté aquí.
 *
 * Se comprueba UNA vez por proceso: preguntar al catálogo en cada petición sería pagar una consulta
 * eterna por un estado que solo cambia el día que se migra.
 */
final class DbTable
{
    /** @var array<string, bool> */
    private static array $vistas = [];

    public static function existe(string $tabla): bool
    {
        if (! array_key_exists($tabla, self::$vistas)) {
            try {
                self::$vistas[$tabla] = Schema::hasTable($tabla);
            } catch (Throwable) {
                // Si ni se puede preguntar, se decide que no. Quedarse sin la función nueva es
                // recuperable; tumbar la pantalla que la aloja, no.
                self::$vistas[$tabla] = false;
            }
        }

        return self::$vistas[$tabla];
    }

    /** Para los tests, que comparten proceso y crean y borran tablas entre casos. */
    public static function olvidar(): void
    {
        self::$vistas = [];
    }
}
