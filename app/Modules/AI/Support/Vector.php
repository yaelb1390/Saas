<?php

declare(strict_types=1);

namespace App\Modules\AI\Support;

use InvalidArgumentException;

/**
 * Utilidades de vectores para la recuperación por similitud.
 */
final class Vector
{
    /**
     * Similitud coseno entre dos vectores. Devuelve 0 si algún vector es nulo.
     *
     * EXIGE QUE MIDAN LO MISMO, y antes no: recortaba al más corto con `min(count($a), count($b))`.
     * Eso hacía que comparar un vector de Gemini (768) contra uno del proveedor local (128) no diera
     * error: comparaba los primeros 128 componentes de dos espacios sin ninguna relación y devolvía
     * un número con pinta de similitud calculado sobre puro ruido. La pantalla lo pintaba como
     * «% afín» y nadie podía saber que era mentira.
     *
     * Comparar dos espacios distintos es un error de programación, no un caso a tolerar. Quien llama
     * —`RagService::retrieve`— filtra por la firma del proveedor para que nunca llegue aquí.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException(
                'No se pueden comparar vectores de distinto tamaño ('.count($a).' y '.count($b).'): '
                .'son espacios distintos y el resultado no significaría nada.'
            );
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $valor) {
            $dot += $valor * $b[$i];
            $normA += $valor * $valor;
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
