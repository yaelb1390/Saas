<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Search;

use App\Modules\Core\Models\SystemEvent;
use Illuminate\Http\Request;

/**
 * Lo que el operador pidió mirar, ya saneado.
 *
 * Todo lo que llega de la dirección se trata como texto de un desconocido. Antes cada valor se leía con
 * `request('empresa')` sin más, y uno que no fuera un número —`?empresa=abc`— llegaba tal cual a una
 * comparación con una columna numérica: SQLite lo perdona, PostgreSQL responde `invalid input syntax for
 * type bigint` y la pantalla se caía con un 500 por escribir mal una dirección. Aquí un valor que no
 * es lo que debe se DESCARTA, en silencio, y la pantalla se pinta como si no se hubiera pedido.
 *
 * Es un objeto de valor y nunca lanza: se construye con `fromRequest()` y todo lo que expone ya es
 * seguro para pegarlo en una consulta.
 */
final class MonitoringFilters
{
    /** Las pestañas de la pantalla. La primera es la de arranque. */
    public const PESTANAS = ['resumen', 'registro', 'errores', 'empresas', 'actividad'];

    /** El estado de un error. `todos` no filtra. */
    public const ESTADOS = ['active', 'resolved', 'ignored', 'todos'];

    /** Cómo se ordenan los errores. */
    public const ORDENES = ['recientes', 'frecuentes', 'empresas'];

    /** Las ventanas de fechas que se ofrecen, en días. `todo` es sin límite. */
    public const VENTANAS = [7, 30, 90];

    /** Al BUSCAR texto sin haber elegido ventana, se mira solo esto: un `like '%…%'` recorre la tabla. */
    public const VENTANA_AL_BUSCAR = 7;

    private const LARGO_MAXIMO_BUSQUEDA = 100;

    /**
     * @param  ?int  $ventana  Días hacia atrás que se miran, o null si no hay límite. Ya incluye el valor
     *                         por omisión al buscar; `$ventanaElegida` es lo que el operador pidió.
     * @param  ?string  $ventanaElegida  '7', '30', '90', 'todo', o null si no eligió.
     */
    private function __construct(
        public readonly string $pestana,
        public readonly ?int $empresa,
        public readonly ?string $busca,
        public readonly ?string $familia,
        public readonly ?string $nivel,
        public readonly ?string $servicio,
        public readonly string $estado,
        public readonly string $orden,
        public readonly ?int $ventana,
        public readonly ?string $ventanaElegida,
        public readonly bool $enDetalle,
        public readonly ?string $accion,
    ) {}

    /**
     * @param  array<string, string>  $familias  las familias del registro que existen (clave => nombre)
     * @param  array<string, string>  $acciones  las acciones de la auditoría que existen (clave => nombre)
     */
    public static function fromRequest(Request $request, array $familias, array $acciones): self
    {
        $busca = self::texto($request->query('busca'));
        $empresa = self::entero($request->query('empresa'));
        $familia = self::deLista($request->query('familia'), array_keys($familias));
        $nivel = self::deLista($request->query('nivel'), [SystemEvent::INFO, SystemEvent::AVISO, SystemEvent::GRAVE]);
        $servicio = self::servicio($request->query('servicio'));
        $accion = self::deLista($request->query('accion'), array_keys($acciones));

        $estadoElegido = self::deLista($request->query('estado'), self::ESTADOS);
        $ordenElegido = self::deLista($request->query('orden'), self::ORDENES);

        [$ventana, $ventanaElegida] = self::ventana($request->query('dias'), $busca !== null);

        return new self(
            pestana: self::pestana(
                $request->query('pestana'),
                registro: $busca !== null || $familia !== null || $nivel !== null || $servicio !== null
                    || $ventanaElegida !== null || $empresa !== null,
                actividad: $accion !== null,
                errores: $estadoElegido !== null || $ordenElegido !== null,
            ),
            empresa: $empresa,
            busca: $busca,
            familia: $familia,
            nivel: $nivel,
            servicio: $servicio,
            estado: $estadoElegido ?? 'active',
            orden: $ordenElegido ?? 'recientes',
            ventana: $ventana,
            ventanaElegida: $ventanaElegida,
            enDetalle: $request->query('en_detalle') === '1',
            accion: $accion,
        );
    }

    /**
     * ¿Hay algún filtro puesto? Sirve para decir «no hay nada con esos filtros» en vez de «todavía no hay
     * nada», que son mensajes distintos.
     */
    public function hayFiltros(): bool
    {
        return $this->busca !== null || $this->familia !== null || $this->nivel !== null
            || $this->servicio !== null || $this->empresa !== null || $this->ventana !== null
            || $this->accion !== null || $this->estado !== 'active';
    }

    /**
     * La pestaña que se abre.
     *
     * Manda la que se pida, si existe. Si no se pide ninguna, se deduce de lo que se filtró: alguien que
     * llega con `?busca=ladron` viene del formulario del registro, y llevarlo al resumen sería perderle
     * lo que acaba de buscar. Con nada pedido, el resumen.
     */
    private static function pestana(mixed $pedida, bool $registro, bool $actividad, bool $errores): string
    {
        if (is_string($pedida) && in_array($pedida, self::PESTANAS, true)) {
            return $pedida;
        }

        return match (true) {
            $actividad => 'actividad',
            $registro => 'registro',
            $errores => 'errores',
            default => 'resumen',
        };
    }

    /**
     * @return array{0: ?int, 1: ?string} la ventana efectiva y la elegida
     */
    private static function ventana(mixed $pedida, bool $buscando): array
    {
        if ($pedida === 'todo') {
            return [null, 'todo'];
        }

        if (is_string($pedida) && ctype_digit($pedida) && in_array((int) $pedida, self::VENTANAS, true)) {
            return [(int) $pedida, $pedida];
        }

        return [$buscando ? self::VENTANA_AL_BUSCAR : null, null];
    }

    private static function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $texto = mb_substr(trim($valor), 0, self::LARGO_MAXIMO_BUSQUEDA);

        return $texto === '' ? null : $texto;
    }

    /** Un entero positivo con sentido; cualquier otra cosa es «no se pidió». */
    private static function entero(mixed $valor): ?int
    {
        if (! is_string($valor) || $valor === '' || strlen($valor) > 15 || ! ctype_digit($valor)) {
            return null;
        }

        return (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * @param  list<string>  $permitidos
     */
    private static function deLista(mixed $valor, array $permitidos): ?string
    {
        return is_string($valor) && in_array($valor, $permitidos, true) ? $valor : null;
    }

    /** Un servicio es una palabra corta en minúsculas: `polar`, `evolution`. Nada más llega a la consulta. */
    private static function servicio(mixed $valor): ?string
    {
        return is_string($valor) && preg_match('/^[a-z0-9_]{1,30}$/', $valor) === 1 ? $valor : null;
    }
}
