<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Buscar texto sin que importen las mayúsculas, en cualquier base de datos.
 *
 * EXISTE POR UN FALLO QUE COSTABA VENTAS. La búsqueda de productos se escribió así:
 *
 *     $query->orWhere($columna, 'like', '%'.$termino.'%');
 *
 * En MySQL eso no distingue mayúsculas y todo parecía correcto. **En PostgreSQL sí las distingue**,
 * y aquí la base es PostgreSQL. Resultado: quien escribía «bomba» en el mostrador no encontraba
 * nada, y «Bomba» encontraba tres. Nadie lo reporta como fallo —se asume que el artículo no está en
 * el catálogo— y el dependiente acaba diciéndole a un cliente que no hay algo que está en el estante.
 *
 * Peor todavía en el bot de WhatsApp: la gente escribe en minúsculas, así que a «tienen bomba de
 * agua?» contestaba que no sabía, aunque el producto estuviera en el catálogo con su precio.
 *
 * `lower()` en vez de `ilike` a propósito: `ilike` solo existe en PostgreSQL y los tests corren
 * sobre SQLite. Una consulta que solo se puede probar en producción no vale de nada.
 */
final class BusquedaTexto
{
    /**
     * El patrón para un LIKE, en minúsculas y con los comodines del usuario neutralizados.
     *
     * Un cliente que busca «100%» no debe acabar buscando «cualquier cosa»: el `%` y el `_` que él
     * escriba son texto, no comodines.
     */
    public static function patron(string $termino): string
    {
        return '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], trim($termino))).'%';
    }

    /**
     * El patrón de «empieza por», para poder ordenar lo que empieza antes que lo que solo contiene.
     *
     * Quien teclea «bom» en el mostrador está pensando en «Bomba», no en «Turbo bomba»: si las dos
     * salen mezcladas hay que leerse la lista entera para encontrar lo que se buscaba. Con el prefijo
     * primero, lo que se quería suele ser la primera fila y basta con pulsar Enter.
     */
    public static function prefijo(string $termino): string
    {
        return mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], trim($termino))).'%';
    }

    /**
     * El trozo de SQL que hay que pegar detrás de un LIKE para que el escape funcione.
     *
     * PostgreSQL toma la barra invertida como carácter de escape por su cuenta; **SQLite no tiene
     * ninguno por omisión**, así que sin esto el `\%` de {@see patron()} no protegía nada ahí: quien
     * buscara «%» acabaría buscando «cualquier cosa». Se declara y deja de depender de la base.
     */
    public const ESCAPE = " escape '\\'";

    /**
     * Añade a la consulta «alguna de estas columnas contiene el término».
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $consulta
     * @param  array<int, string>  $columnas
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function enCualquiera(Builder $consulta, array $columnas, string $termino): Builder
    {
        $patron = self::patron($termino);

        foreach ($columnas as $columna) {
            self::comprobarNombre($columna);
        }

        // Agrupado en su propio paréntesis: sin esto, un `orWhere` se llevaría por delante cualquier
        // condición anterior —como «solo los activos»— y la búsqueda devolvería también lo apagado.
        return $consulta->where(function (Builder $q) use ($columnas, $patron): void {
            foreach ($columnas as $columna) {
                $q->orWhereRaw('lower('.$columna.') like ?'.self::ESCAPE, [$patron]);
            }
        });
    }

    /**
     * El nombre de columna va dentro de SQL en crudo, así que se comprueba.
     *
     * Hoy todos los que llegan están escritos en el código y ninguno viene de fuera. La comprobación
     * es para el día en que alguien acepte una columna «ordenar por» de la URL y la pase por aquí sin
     * darse cuenta de que esto no la escapa.
     */
    private static function comprobarNombre(string $columna): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $columna) !== 1) {
            throw new InvalidArgumentException("Nombre de columna no válido para buscar: «{$columna}».");
        }
    }
}
