<?php

declare(strict_types=1);

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Enums\ExpenseGroup;
use App\Modules\Finance\Models\Expense;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * La tabla dinámica de gastos: filas por lo que se elija, columnas por tiempo, dinero en las celdas.
 *
 * POR QUÉ EXISTE. Una lista de gastos no contesta la única pregunta que se le hace a esta pantalla:
 * «¿en qué se me va el dinero, y va a más o a menos?». Para eso hace falta cruzar dos ejes —en qué se
 * gasta contra cuándo— y eso es una tabla dinámica.
 *
 * EL CRUCE SE HACE EN PHP, NO EN SQL, y no es por comodidad. Truncar una fecha por mes se escribe
 * `to_char(paid_at,'YYYY-MM')` en PostgreSQL y `strftime('%Y-%m', paid_at)` en SQLite. Producción es
 * PostgreSQL y la suite corre sobre SQLite en memoria: agrupar en SQL significaría que los tests
 * prueban una consulta que no es la que se ejecuta de verdad. Ya ha pasado en este proyecto —una
 * búsqueda que pasaba los tests y reventaba en producción—, así que aquí se agrupa con Carbon, que se
 * comporta igual en los dos sitios.
 *
 * El coste es traer las filas del período a memoria. Para el gasto de un negocio pequeño —cientos de
 * apuntes al mes— es intrascendente, y se acota con el propio rango de fechas que ya filtra la
 * pantalla.
 */
final readonly class TablaDinamicaDeGastos
{
    /** Por qué se puede agrupar en las filas. */
    public const FILAS = ['categoria', 'concepto', 'cuenta', 'proveedor'];

    /** Y cómo se parten las columnas en el tiempo. */
    public const COLUMNAS = ['dia', 'semana', 'mes'];

    /**
     * @param  array<int, array{clave: string, rotulo: string}>  $columnas
     * @param  array<int, array{clave: string, rotulo: string, tono: string, celdas: array<string, string>, total: string}>  $filas
     * @param  array<string, string>  $totales
     */
    private function __construct(
        public array $columnas,
        public array $filas,
        public array $totales,
        public string $granTotal,
    ) {}

    /**
     * @param  Collection<int, Expense>  $gastos  Los del período, ya filtrados por la pantalla.
     */
    public static function de(Collection $gastos, string $filas, string $columnas, CarbonInterface $desde, CarbonInterface $hasta): self
    {
        $filas = in_array($filas, self::FILAS, true) ? $filas : 'categoria';
        $columnas = in_array($columnas, self::COLUMNAS, true) ? $columnas : self::porOmision($desde, $hasta);

        $ejeX = self::ejeDelTiempo($desde, $hasta, $columnas);
        $acumulado = [];
        $totales = array_fill_keys(array_column($ejeX, 'clave'), '0.00');
        $granTotal = '0.00';

        foreach ($gastos as $gasto) {
            $fila = self::filaDe($gasto, $filas);
            $columna = self::claveDelTiempo($gasto->paid_at, $columnas);
            $monto = (string) $gasto->amount;

            $acumulado[$fila['clave']] ??= ['rotulo' => $fila['rotulo'], 'tono' => $fila['tono'], 'celdas' => [], 'total' => '0.00'];
            $acumulado[$fila['clave']]['celdas'][$columna] = bcadd($acumulado[$fila['clave']]['celdas'][$columna] ?? '0', $monto, 2);
            $acumulado[$fila['clave']]['total'] = bcadd($acumulado[$fila['clave']]['total'], $monto, 2);

            // Una columna fuera del eje solo puede venir de un gasto fuera del rango; no se suma a
            // los totales para que el pie de la tabla siga cuadrando con lo que se ve.
            if (array_key_exists($columna, $totales)) {
                $totales[$columna] = bcadd($totales[$columna], $monto, 2);
                $granTotal = bcadd($granTotal, $monto, 2);
            }
        }

        // De mayor a menor: en una tabla de gastos, lo que más pesa es lo primero que hay que mirar.
        uasort($acumulado, static fn (array $a, array $b): int => bccomp($b['total'], $a['total'], 2));

        $salida = [];

        foreach ($acumulado as $clave => $datos) {
            $salida[] = ['clave' => (string) $clave, ...$datos];
        }

        return new self($ejeX, $salida, $totales, $granTotal);
    }

    /**
     * Los mismos números, en la forma que come un gráfico.
     *
     * SALE DEL MISMO CÁLCULO QUE LA TABLA, a propósito: si el gráfico consultara por su cuenta,
     * bastaría un filtro distinto para que la barra dijera una cosa y la celda de al lado otra, y
     * quien mira deja de fiarse de las dos.
     *
     * SE CORTAN LAS SERIES EN SIETE. Con trece categorías, un gráfico de trece colores no se lee:
     * dos tonos vecinos son indistinguibles para quien no ve bien el color, y para el resto tampoco
     * es que ayude. Las seis primeras van sueltas y el resto se pliega en «Otros» — que sigue
     * sumando lo mismo, así que el total del gráfico cuadra con el de la tabla.
     *
     * @return array{columnas: array<int, string>, series: array<int, array{nombre: string, datos: array<int, float>}>, ranking: array<int, array{nombre: string, total: float}>}
     */
    public function paraGrafico(int $tope = 6): array
    {
        $columnas = array_column($this->columnas, 'rotulo');
        $claves = array_column($this->columnas, 'clave');

        $series = [];
        $otros = array_fill(0, count($claves), 0.0);
        $hayOtros = false;

        foreach ($this->filas as $i => $fila) {
            $datos = array_map(
                static fn (string $clave): float => (float) ($fila['celdas'][$clave] ?? 0),
                $claves,
            );

            if ($i < $tope) {
                $series[] = ['nombre' => $fila['rotulo'], 'datos' => $datos];

                continue;
            }

            $hayOtros = true;

            foreach ($datos as $j => $valor) {
                $otros[$j] += $valor;
            }
        }

        if ($hayOtros) {
            $series[] = ['nombre' => 'Otros', 'datos' => $otros];
        }

        return [
            'columnas' => $columnas,
            'series' => $series,
            // El ranking va entero: es una lista con barras, no una paleta, así que trece filas se
            // leen igual de bien que tres.
            'ranking' => array_map(
                static fn (array $fila): array => ['nombre' => $fila['rotulo'], 'total' => (float) $fila['total']],
                $this->filas,
            ),
        ];
    }

    public function estaVacia(): bool
    {
        return $this->filas === [];
    }

    /**
     * El total de una celda, o null si ahí no se gastó nada.
     *
     * Null y cero NO son lo mismo en una tabla dinámica: cero es «se gastó y salió cero», que no
     * pasa nunca, y null es «no hubo gasto». La vista pinta un guion en vez de «0.00», que es lo que
     * hace legible una rejilla llena de huecos.
     */
    public function celda(array $fila, string $columna): ?string
    {
        return $fila['celdas'][$columna] ?? null;
    }

    /**
     * Con qué granularidad se parte el tiempo si no la eligen.
     *
     * Un rango corto por días y uno largo por meses: doce columnas caben y trescientas sesenta y
     * cinco no, y una tabla con una columna por día de un año no se lee, se sufre.
     */
    private static function porOmision(CarbonInterface $desde, CarbonInterface $hasta): string
    {
        $dias = $desde->diffInDays($hasta);

        return match (true) {
            $dias <= 31 => 'dia',
            $dias <= 120 => 'semana',
            default => 'mes',
        };
    }

    /**
     * Las columnas del período, TODAS, incluidas las que no tienen gasto.
     *
     * Es lo que distingue una tabla dinámica de una lista agrupada: si solo salieran los días con
     * movimiento, un hueco de tres días sin gastar no se vería, y el hueco es justo lo que se quiere
     * ver al mirar en qué se va el dinero.
     *
     * @return array<int, array{clave: string, rotulo: string}>
     */
    private static function ejeDelTiempo(CarbonInterface $desde, CarbonInterface $hasta, string $granularidad): array
    {
        $eje = [];
        $cursor = $desde->copy()->startOfDay();
        $fin = $hasta->copy()->endOfDay();

        // Tope de seguridad: un rango absurdo no debe generar mil columnas ni colgar la pantalla.
        while ($cursor <= $fin && count($eje) < 400) {
            $clave = self::claveDelTiempo($cursor, $granularidad);

            if (! isset($eje[$clave])) {
                $eje[$clave] = ['clave' => $clave, 'rotulo' => self::rotuloDelTiempo($cursor, $granularidad)];
            }

            $cursor = match ($granularidad) {
                'dia' => $cursor->addDay(),
                'semana' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };
        }

        return array_values($eje);
    }

    private static function claveDelTiempo(?CarbonInterface $fecha, string $granularidad): string
    {
        if ($fecha === null) {
            return 'sin-fecha';
        }

        return match ($granularidad) {
            'dia' => $fecha->format('Y-m-d'),
            'semana' => $fecha->copy()->startOfWeek()->format('Y-m-d'),
            default => $fecha->format('Y-m'),
        };
    }

    private static function rotuloDelTiempo(CarbonInterface $fecha, string $granularidad): string
    {
        return match ($granularidad) {
            // Día y mes, sin año: el año ya está en el rango de arriba y repetirlo en treinta
            // columnas las hace el doble de anchas sin decir nada nuevo.
            'dia' => $fecha->format('d/m'),
            'semana' => $fecha->copy()->startOfWeek()->format('d/m'),
            default => ucfirst($fecha->locale('es')->isoFormat('MMM YY')),
        };
    }

    /**
     * A qué fila va un gasto, según el eje elegido.
     *
     * @return array{clave: string, rotulo: string, tono: string}
     */
    private static function filaDe(Expense $gasto, string $eje): array
    {
        return match ($eje) {
            'concepto' => [
                'clave' => 'c'.($gasto->expense_category_id ?? 0),
                'rotulo' => $gasto->category?->name ?? 'Sin concepto',
                'tono' => $gasto->category?->category?->tono() ?? 'slate',
            ],
            'cuenta' => [
                'clave' => 'a'.($gasto->account_id ?? 0),
                'rotulo' => $gasto->account?->name ?? 'Sin cuenta',
                'tono' => 'slate',
            ],
            'proveedor' => [
                // El proveedor de la ficha manda sobre el nombre escrito a mano, que es el respaldo
                // de cuando se paga a alguien que no está dado de alta.
                'clave' => $gasto->supplier_id !== null ? 'p'.$gasto->supplier_id : 'n'.mb_strtolower(trim((string) $gasto->supplier_name)),
                'rotulo' => $gasto->supplier?->name ?? ($gasto->supplier_name ?: 'Sin proveedor'),
                'tono' => 'slate',
            ],
            default => self::filaPorCategoria($gasto),
        };
    }

    /** @return array{clave: string, rotulo: string, tono: string} */
    private static function filaPorCategoria(Expense $gasto): array
    {
        $categoria = $gasto->category?->category;

        // Sin clasificar se agrupa aparte y NO en «Otros»: son cosas distintas, y mezclarlas
        // escondería que hay conceptos que nadie ha revisado todavía.
        if (! $categoria instanceof ExpenseGroup) {
            return ['clave' => 'sin', 'rotulo' => 'Sin clasificar', 'tono' => 'slate'];
        }

        return ['clave' => $categoria->value, 'rotulo' => $categoria->label(), 'tono' => $categoria->tono()];
    }
}
