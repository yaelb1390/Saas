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

    /** @var array<string, bool> memo de columnas, con la clave «tabla.columna» */
    private static array $columnas = [];

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

    /**
     * ¿Existe ya esta COLUMNA?
     *
     * Mismo problema que con la tabla —el código sale antes que la migración— pero un escalón más
     * fino: la tabla lleva años ahí y lo que falta es la columna que se acaba de añadir.
     *
     * Y con el mismo memo, que aquí no es un detalle: `Schema::hasColumn()` interroga al catálogo del
     * motor y no es barata. Llamarla suelta en la campana de alertas —que se pinta en TODAS las
     * páginas— se llevó por delante el presupuesto de consultas del dashboard, y el test lo cazó.
     */
    public static function tieneColumna(string $tabla, string $columna): bool
    {
        $clave = $tabla.'.'.$columna;

        if (! array_key_exists($clave, self::$columnas)) {
            try {
                self::$columnas[$clave] = self::existe($tabla) && Schema::hasColumn($tabla, $columna);
            } catch (Throwable) {
                self::$columnas[$clave] = false;
            }
        }

        return self::$columnas[$clave];
    }

    /** Para los tests, que comparten proceso y crean y borran tablas entre casos. */
    public static function olvidar(): void
    {
        self::$vistas = [];
        self::$columnas = [];
    }
}
