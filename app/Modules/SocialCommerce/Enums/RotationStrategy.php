<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Enums;

/**
 * Cómo se elige qué plantilla responde en cada comentario.
 *
 * SOLO existe `Random`, y es a propósito, no una limitación temporal. Se investigó contra el
 * código real de {@see \App\Modules\Social\Services\ZernioClient} (ver
 * docs/social-commerce/SOCIAL_COMMERCE_META_RESEARCH.md): Zernio elige la variante AL AZAR, en su
 * propio servidor, en el instante en que responde. Nuestra aplicación nunca ve ese momento antes
 * de que la respuesta ya haya salido, así que no hay forma de pedir «secuencial» ni «la menos
 * usada recientemente» sin dejar de usar el motor de automatización de Zernio.
 *
 * Siguiendo la regla del prompt del proyecto («si una acción no está oficialmente disponible, no
 * se implementa por vías no oficiales»), esas dos estrategias no se ofrecen ni se simulan.
 */
enum RotationStrategy: string
{
    case Random = 'random';

    public function label(): string
    {
        return match ($this) {
            self::Random => 'Aleatoria (la que decide Zernio al responder)',
        };
    }
}
