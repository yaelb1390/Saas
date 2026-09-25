<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\SocialCommerce\Models\Rule;

/**
 * Réplica LOCAL del algoritmo de coincidencia de Zernio, solo para el modo sandbox (arquitectura,
 * sección 3): quien de verdad decide en producción es Zernio, esto es una aproximación para poder
 * probar una regla sin publicar nada ni esperar un comentario real.
 *
 * La tolerancia a erratas usa distancia de Levenshtein con el mismo criterio que describe el panel
 * de Redes sociales («una letra de diferencia en palabras cortas y dos a partir de ocho letras»),
 * pero es una aproximación: el algoritmo exacto de Zernio no es público, así que el resultado aquí
 * puede no coincidir al milímetro con lo que pase en Instagram de verdad.
 */
final class KeywordMatcher
{
    /** La palabra clave que coincidió, o null si ninguna lo hace. */
    public function matches(Rule $rule, string $comentario): ?string
    {
        $normalizado = $this->normalizar($comentario);
        $palabras = $this->tokenizar($normalizado);

        foreach ($rule->keywords as $keyword) {
            $keywordNormalizado = $this->normalizar((string) $keyword);

            if ($keywordNormalizado === '') {
                continue;
            }

            $coincide = match ($rule->match_mode) {
                'exact' => $normalizado === $keywordNormalizado,
                'contains' => str_contains($normalizado, $keywordNormalizado),
                default => $this->coincideComoPalabra($palabras, $keywordNormalizado, $rule->typo_tolerance),
            };

            if ($coincide) {
                return (string) $keyword;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $palabras
     */
    private function coincideComoPalabra(array $palabras, string $keyword, bool $tolerancia): bool
    {
        foreach ($palabras as $palabra) {
            if ($palabra === $keyword) {
                return true;
            }

            if ($tolerancia && $this->distanciaAceptable($palabra, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function distanciaAceptable(string $palabra, string $keyword): bool
    {
        $tope = mb_strlen($keyword) >= 8 ? 2 : 1;

        return levenshtein($palabra, $keyword) <= $tope;
    }

    /**
     * Minúsculas, sin acentos, sin signos — para que «¿Cuánto?» pueda compararse con «cuanto» sin
     * modificar el mensaje original almacenado (prompt maestro, sección 5).
     */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        $texto = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * @return list<string>
     */
    private function tokenizar(string $normalizado): array
    {
        return $normalizado === '' ? [] : explode(' ', $normalizado);
    }
}
