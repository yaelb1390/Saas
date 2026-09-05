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
        return '%'.mb_strtolower(self::neutralizar($termino)).'%';
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
        return mb_strtolower(self::neutralizar($termino)).'%';
    }

    /**
     * Quita el poder de comodín a lo que escribió el usuario, con `!` por delante.
     *
     * EL SIGNO DE ADMIRACIÓN SE ESCAPA PRIMERO, y ese orden no es cosmético: si se hiciera después,
     * el `!` que añaden los pasos anteriores se volvería a escapar y el patrón buscaría otra cosa.
     */
    private static function neutralizar(string $termino): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($termino));
    }

    /**
     * El trozo de SQL que hay que pegar detrás de un LIKE para que el escape funcione.
     *
     * Hace falta porque **SQLite no tiene carácter de escape por omisión**: sin esto, lo que
     * {@see neutralizar()} protege no quedaría protegido ahí, y quien buscara «%» acabaría buscando
     * «cualquier cosa».
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * POR QUÉ ES «!» Y NO LA BARRA INVERTIDA. Esto tumbó la búsqueda ENTERA en producción —el
     * mostrador, el monitoreo y el buscador del bot de WhatsApp— mientras en local funcionaba
     * perfectamente. El error era:
     *
     *     SQLSTATE[HY093]: Invalid parameter number: parameter was not defined
     *
     * En producción la conexión va con `DB_EMULATE_PREPARES=true`, que hace falta para el pooler de
     * Supabase. En ese modo **PDO analiza el SQL él mismo** para sustituir los `?`, y su analizador
     * sí trata la barra invertida como escape dentro de una cadena. Al llegar a `escape '\'` cree
     * que la barra escapa la comilla de cierre, da la cadena por no terminada, y a partir de ahí
     * deja de reconocer los `?` que vienen detrás: la cuenta de parámetros no cuadra y revienta.
     *
     * En local no se veía porque sin emulación quien analiza el SQL es PostgreSQL, y él sí lee
     * `'\'` como una barra literal. Es decir: un fallo que solo existe en producción.
     *
     * `!` no significa nada dentro de una cadena SQL, así que ningún analizador se confunde. Y es
     * SQL estándar: funciona igual en PostgreSQL y en SQLite.
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     */
    public const ESCAPE = " escape '!'";

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
