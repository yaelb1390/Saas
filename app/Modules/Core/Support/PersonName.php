<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * El nombre de pila de una persona, para saludar en un correo: «Hola, Yael» y no «Hola, Yael Berroa Pérez».
 *
 * Si el nombre viene vacío o es una sola palabra, se devuelve tal cual: es lo que hay que saludar, y
 * un correo con «Hola, » a secas es peor que uno con el nombre de la empresa.
 */
final class PersonName
{
    public static function first(string $fullName): string
    {
        $clean = trim($fullName);

        return trim(strtok($clean, ' ') ?: $clean);
    }
}
