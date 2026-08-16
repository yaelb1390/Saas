<?php

declare(strict_types=1);

namespace App\Modules\POS\Exceptions;

use DomainException;

/**
 * Se intentó cobrar algo que el propio negocio marcó como agotado.
 *
 * Se lanza en vez de saltarse la línea en silencio. Descartarla sin decir nada cobraría menos de lo
 * que el cliente tiene delante y el cajero no vería el descuadre hasta contar la caja; y peor, el
 * cliente se iría creyendo que lleva algo que no lleva.
 *
 * Que un producto agotado siga apareciendo en la rejilla —en gris— es lo que permite volver a
 * encenderlo mañana, pero también significa que se puede tocar por descuido. Esta es la red.
 */
final class ProductUnavailableException extends DomainException
{
    public static function para(string $producto): self
    {
        return new self("«{$producto}» está marcado como agotado. Quítalo del pedido o vuelve a activarlo.");
    }
}
