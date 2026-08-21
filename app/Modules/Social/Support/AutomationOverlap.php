<?php

declare(strict_types=1);

namespace App\Modules\Social\Support;

/**
 * Qué automatizaciones no van a llegar a responder porque otra se les adelanta.
 *
 * EL PROBLEMA QUE RESUELVE, visto en una cuenta real: cinco automatizaciones con EXACTAMENTE las
 * mismas seis palabras clave. Un comentario lo atiende una sola, así que cuatro no disparaban nunca
 * —una de ellas con cero registros en cinco días— y la pantalla decía «espera a que alguien
 * comente», invitando a crear otra igual. Que es lo que había pasado cinco veces.
 *
 * Quién gana se decide por FECHA DE CREACIÓN, y no es una regla inventada: es lo que dicen los datos
 * de esa cuenta —la del 16 de agosto tenía seis registros y la del 20, cero— y coincide con que la
 * especificación diga que se pueden apilar automatizaciones de toda la cuenta «each with its own
 * keyword set»: con SU juego de palabras, no repetido.
 */
final class AutomationOverlap
{
    /**
     * Las que quedan tapadas, indexadas por su identificador.
     *
     * @param  array<int, array<string, mixed>>  $automatizaciones  tal como las devuelve ZernioClient
     * @return array<string, array{nombre: string, id: string}> quién se les adelanta
     */
    public static function tapadas(array $automatizaciones): array
    {
        // De la más antigua a la más nueva: quien llega primero se queda con los comentarios.
        usort($automatizaciones, static fn (array $a, array $b): int => ($a['createdAt'] ?? '') <=> ($b['createdAt'] ?? ''));

        $tapadas = [];

        foreach ($automatizaciones as $i => $nueva) {
            // Una apagada no responde a nadie, así que decirle que está tapada es ruido.
            if (($nueva['isActive'] ?? false) !== true) {
                continue;
            }

            foreach (array_slice($automatizaciones, 0, $i) as $vieja) {
                // Y una apagada tampoco tapa: no consume ningún comentario.
                if (($vieja['isActive'] ?? false) !== true) {
                    continue;
                }

                if (self::compiten($vieja, $nueva)) {
                    $tapadas[(string) $nueva['id']] = [
                        'nombre' => (string) ($vieja['name'] ?? 'otra automatización'),
                        'id' => (string) ($vieja['id'] ?? ''),
                    ];

                    // Con saber quién se adelanta basta; si hubiera tres, la primera es la que manda.
                    break;
                }
            }
        }

        return $tapadas;
    }

    /**
     * ¿Se disputan el mismo comentario?
     *
     * Hacen falta las tres cosas. Que falte una es lo que separa una colisión de verdad de dos
     * automatizaciones que conviven bien.
     */
    private static function compiten(array $a, array $b): bool
    {
        // 1. La misma cuenta. Dos cuentas distintas no comparten un solo comentario.
        if ((string) ($a['accountId'] ?? '') !== (string) ($b['accountId'] ?? '')) {
            return false;
        }

        // 2. El mismo disparador: un comentario y una respuesta a una historia no son lo mismo.
        if ((string) ($a['trigger'] ?? 'comment') !== (string) ($b['trigger'] ?? 'comment')) {
            return false;
        }

        /*
         * 3. El mismo ámbito.
         *
         * Las dos de toda la cuenta, o las dos atadas a la MISMA publicación. Una atada a una
         * publicación y otra de toda la cuenta NO compiten: la especificación dice que «per-post
         * automations take priority on their post», así que cada una manda en su terreno y las dos
         * tienen sentido. Marcar eso como problema sería mentir sobre una configuración correcta.
         */
        if (($a['postId'] ?? null) !== ($b['postId'] ?? null)) {
            return false;
        }

        return self::compartenPalabra($a, $b);
    }

    private static function compartenPalabra(array $a, array $b): bool
    {
        $suyas = self::normalizar((array) ($b['keywords'] ?? []));

        foreach (self::normalizar((array) ($a['keywords'] ?? [])) as $palabra) {
            if (in_array($palabra, $suyas, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas y sin espacios sobrantes: «Precio» y «precio » son la misma palabra para quien
     * comenta, y compararlas tal cual dejaría pasar la colisión más obvia de todas.
     *
     * @param  array<int, mixed>  $palabras
     * @return list<string>
     */
    private static function normalizar(array $palabras): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $p): string => mb_strtolower(trim((string) $p)),
            $palabras,
        ), static fn (string $p): bool => $p !== ''));
    }
}
