<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * Reparte un total en N cuotas y les pone fecha.
 *
 * Es matemática de dinero y estaba escrita dentro de `LoanService`, encerrada en un método privado
 * que además creaba las filas. Cuando el dealer de vehículos estrenó su propio financiamiento hacían
 * falta las mismas cuentas, y copiarlas habría dejado DOS repartos de capital e interés que hay que
 * acordarse de corregir a la vez: es exactamente donde salen los errores caros.
 *
 * Aquí no se sabe nada de préstamos ni de vehículos. Entran números y sale un array; quien llame
 * decide qué guardar y dónde. Por eso la fecha se avanza con una función que se recibe de fuera: el
 * préstamo cobra hasta a diario y el dealer no, y esta clase no tiene por qué opinar.
 *
 * El interés es PLANO sobre el total, no amortización sobre saldo. Es como se presta y como se vende
 * a cuotas en la República Dominicana, y cambiarlo aquí cambiaría en silencio lo que ya se le cobra
 * a la gente.
 */
final class PlanDeCuotas
{
    /** Dos decimales: son pesos, y se calcula con bc para no arrastrar los errores del coma flotante. */
    public const ESCALA = 2;

    /**
     * El importe de cada cuota: el total repartido a partes iguales.
     *
     * Se redondea hacia abajo a propósito. Lo que se pierde al dividir lo absorbe la última cuota en
     * `calcular()`, de modo que la suma cuadra exacta con el total. Al revés —redondeando hacia
     * arriba— se le cobraría al cliente unos céntimos de más que nadie sabría justificar.
     */
    public static function importeDeCuota(string $total, int $cuotas): string
    {
        return bcdiv($total, (string) max(1, $cuotas), self::ESCALA);
    }

    /**
     * Las N cuotas, con su fecha, su importe y el reparto capital/interés.
     *
     * LA ÚLTIMA CUOTA ABSORBE EL REDONDEO. Con 100.000 en 3 cuotas, dividir da 33.333,33 y tres de
     * esas suman 99.999,99: falta un céntimo. Se lo lleva la última, así que capital e interés suman
     * exactamente lo prestado y el saldo puede llegar a cero de verdad. Sin esto, cada crédito
     * quedaría abierto para siempre por unos céntimos.
     *
     * @param  Closure(Carbon): Carbon  $avanzar  Cómo se espacia el siguiente vencimiento.
     * @return list<array{number: int, due_date: Carbon, amount: string, principal_portion: string, interest_portion: string}>
     */
    public static function calcular(
        string $capital,
        string $interes,
        int $cuotas,
        Carbon $inicio,
        Closure $avanzar,
    ): array {
        $cuotas = max(1, $cuotas);

        $total = bcadd($capital, $interes, self::ESCALA);
        $importe = self::importeDeCuota($total, $cuotas);

        $capitalPorCuota = bcdiv($capital, (string) $cuotas, self::ESCALA);
        $interesPorCuota = bcdiv($interes, (string) $cuotas, self::ESCALA);

        $vence = $inicio->copy();
        $plan = [];

        for ($n = 1; $n <= $cuotas; $n++) {
            if ($n < $cuotas) {
                $deCapital = $capitalPorCuota;
                $deInteres = $interesPorCuota;
                $deLaCuota = $importe;
            } else {
                // La última: lo que reste, para que capital e interés sumen exacto.
                $deCapital = bcsub($capital, bcmul($capitalPorCuota, (string) ($cuotas - 1), self::ESCALA), self::ESCALA);
                $deInteres = bcsub($interes, bcmul($interesPorCuota, (string) ($cuotas - 1), self::ESCALA), self::ESCALA);
                $deLaCuota = bcadd($deCapital, $deInteres, self::ESCALA);
            }

            $plan[] = [
                'number' => $n,
                'due_date' => $vence->copy(),
                'amount' => $deLaCuota,
                'principal_portion' => $deCapital,
                'interest_portion' => $deInteres,
            ];

            $vence = $avanzar($vence);
        }

        return $plan;
    }
}
