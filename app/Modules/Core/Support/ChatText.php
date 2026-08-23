<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Cómo se lee lo que alguien escribe en un chat.
 *
 * Nació dentro del asistente del panel y salió aquí cuando el bot de WhatsApp necesitó lo mismo.
 * Duplicarlo habría sido la manera de que los dos empezaran a entender cosas distintas: se arregla
 * un caso en uno —«buenas!!» con dos cierres, «ok gracias» con el relleno delante— y el otro se
 * queda atrás sin que nadie se entere hasta que un cliente escribe justo eso.
 *
 * Ofrece las dos formas de comparar, y la diferencia entre ellas importa mucho:
 *
 *  · {@see esAlgunaDe()} exige IGUALDAD. Es la de los saludos.
 *  · {@see contieneAlguna()} busca dentro. Es la de «quiero hablar con una persona».
 */
final class ChatText
{
    /**
     * Minúsculas, sin acentos, sin signos y sin los rellenos con los que se escribe en un chat.
     *
     * Se quitan «buenas!!», «hola." y «ok, gracias» para que las tres caigan en la misma frase: quien
     * escribe en un chat no cuida la puntuación, y una lista que solo case con la forma limpia no
     * acierta casi nunca.
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        $texto = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $texto) ?? $texto;

        // Rellenos que no cambian la intención: «ok gracias» y «gracias ok» son lo mismo.
        $texto = preg_replace('/\b(ok|okay|oki|dale|pues|bueno|este|eh)\b/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/', ' ', $texto) ?? $texto);
    }

    /**
     * ¿El mensaje es SOLO una de estas frases, sin nada más?
     *
     * La comprobación es de IGUALDAD, y ahí está todo el asunto.
     *
     * «Hola, ¿cuánto vale la batida?» lleva un saludo dentro y NO es un saludo: es una pregunta de
     * verdad que hay que contestar. Con «contiene», esa pregunta —y cualquiera que empiece
     * educadamente— se comería un «¡Hola! ¿En qué te ayudo?» y habría que repetirla sin los buenos
     * días.
     *
     * @param  list<string>  $frases
     */
    public static function esAlgunaDe(string $texto, array $frases): bool
    {
        $limpio = self::normalizar($texto);

        if ($limpio === '') {
            return false;
        }

        foreach ($frases as $frase) {
            if ($limpio === self::normalizar($frase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Aparece alguna de estas frases DENTRO del mensaje?
     *
     * Lo contrario de la anterior, y también a propósito: «ok pero quiero hablar con una persona por
     * favor» es exactamente lo que hay que cazar, y exigir igualdad lo dejaría pasar. Se usa solo
     * donde equivocarse de más sale barato.
     *
     * @param  list<string>  $frases
     */
    public static function contieneAlguna(string $texto, array $frases): bool
    {
        $limpio = self::normalizar($texto);

        if ($limpio === '') {
            return false;
        }

        foreach ($frases as $frase) {
            $aguja = self::normalizar($frase);

            if ($aguja !== '' && str_contains($limpio, $aguja)) {
                return true;
            }
        }

        return false;
    }
}
