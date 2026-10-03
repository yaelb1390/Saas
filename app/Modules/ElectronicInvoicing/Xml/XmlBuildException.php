<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Xml;

use RuntimeException;

/**
 * Error al armar el XML a partir de los datos: siempre es un fallo de programación (un campo que el
 * esquema oficial no tiene, o con la forma equivocada), nunca un dato del usuario.
 */
final class XmlBuildException extends RuntimeException
{
    public static function unknownElement(string $ruta): self
    {
        return new self("El esquema oficial no tiene el elemento «{$ruta}».");
    }

    public static function expectedList(string $ruta): self
    {
        return new self("«{$ruta}» se repite en el esquema: se esperaba una lista.");
    }

    public static function tooMany(string $ruta, int $max): self
    {
        return new self("«{$ruta}» admite como máximo {$max} repeticiones.");
    }

    public static function expectedGroup(string $ruta): self
    {
        return new self("«{$ruta}» es un grupo de elementos: se esperaba un array.");
    }

    public static function expectedValue(string $ruta): self
    {
        return new self("«{$ruta}» es un valor simple: no admite un grupo.");
    }
}
