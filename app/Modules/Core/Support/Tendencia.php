<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * El cambio de una cifra respecto a antes, ya listo para pintar.
 *
 * Está aquí y no en cada pantalla porque las tres decisiones delicadas son siempre las mismas y
 * equivocarse en cualquiera de ellas produce un número creíble y falso, que es peor que no poner
 * nada:
 *
 * 1. QUÉ ES BUENO. No es «subir». Que suban las ventas es bueno; que suban los accesos fallidos o
 *    los productos sin existencia es malo. Pintar de verde todo lo que crece convertiría un
 *    problema de seguridad en una buena noticia, así que cada cifra declara su dirección y esta
 *    clase no la adivina.
 *
 * 2. DIVIDIR ENTRE CERO. De 0 a 5 no es «+500 %» ni «+100 %»: no existe porcentaje. Se dice
 *    «nuevo», que es lo que de verdad pasó.
 *
 * 3. DE LA NADA A LA NADA. Si antes era cero y sigue siendo cero no hay nada que contar y no se
 *    pinta nada. Un «0 %» en una tarjeta vacía es ruido.
 */
final class Tendencia
{
    /** Por debajo de esto el cambio se considera nulo: «+0.02 %» es ruido, no una tendencia. */
    private const UMBRAL = 0.05;

    /**
     * @param  float  $antes  el valor en el momento con el que se compara
     * @param  float  $ahora  el valor actual
     * @param  bool|null  $subeEsBueno  true si crecer es bueno, false si es malo, null si da igual
     * @param  string  $detalle  la explicación completa, para el `title` al pasar el ratón
     * @return array{texto: string, signo: string, detalle: string}|null null si no hay nada que enseñar
     */
    public static function calcular(float $antes, float $ahora, ?bool $subeEsBueno, string $detalle): ?array
    {
        // De la nada a la nada.
        if ($antes === 0.0 && $ahora === 0.0) {
            return null;
        }

        // No se puede dividir entre cero, y ningún porcentaje describe honestamente ese salto.
        if ($antes === 0.0) {
            return [
                'texto' => 'nuevo',
                'signo' => self::signo($ahora > 0 ? 1.0 : -1.0, $subeEsBueno),
                'detalle' => $detalle,
            ];
        }

        // abs() en el divisor: con un saldo negativo que mejora, dividir por el número con signo
        // daría el porcentaje al revés y una recuperación se pintaría de rojo.
        $porciento = ($ahora - $antes) / abs($antes) * 100;

        if (abs($porciento) < self::UMBRAL) {
            return ['texto' => '0%', 'signo' => 'neutro', 'detalle' => $detalle];
        }

        return [
            'texto' => self::comoTexto($porciento),
            'signo' => self::signo($porciento, $subeEsBueno),
            'detalle' => $detalle,
        ];
    }

    /**
     * «+12.4%», «−3%». Sin decimal cuando es redondo: el «.0» es ruido en una tarjeta pequeña.
     *
     * El signo de menos es el de verdad (−, U+2212) y no un guion: a este cuerpo de letra un guion
     * se confunde con un separador.
     */
    private static function comoTexto(float $porciento): string
    {
        $redondeado = round($porciento, 1);
        $cifra = $redondeado == (int) $redondeado
            ? number_format(abs($redondeado), 0)
            : number_format(abs($redondeado), 1);

        return ($redondeado > 0 ? '+' : '−').$cifra.'%';
    }

    /** Bueno, malo o neutro: nunca «sube» o «baja». Ver el punto 1 de arriba. */
    private static function signo(float $porciento, ?bool $subeEsBueno): string
    {
        if ($subeEsBueno === null) {
            return 'neutro';
        }

        return ($porciento > 0) === $subeEsBueno ? 'bueno' : 'malo';
    }
}
