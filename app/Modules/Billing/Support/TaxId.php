<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/**
 * Documento de identificación tributaria dominicano: RNC (9 dígitos) o Cédula (11 dígitos).
 *
 * Valida el dígito verificador, no solo la longitud: un número con el formato correcto pero
 * mal construido sería rechazado por la DGII al recibir el 607, y para entonces la factura ya
 * está emitida y el NCF consumido. Conviene detectarlo en la emisión.
 */
final readonly class TaxId
{
    /** Pesos oficiales del RNC para el cálculo del dígito verificador. */
    private const RNC_WEIGHTS = [7, 9, 8, 6, 5, 4, 3, 2];

    private function __construct(
        public string $value,
        public TaxIdKind $kind,
    ) {}

    /**
     * Construye el documento si es válido; devuelve null si no lo es.
     */
    public static function tryParse(?string $raw): ?self
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';

        return match (strlen($digits)) {
            9 => self::isValidRnc($digits) ? new self($digits, TaxIdKind::Rnc) : null,
            11 => self::isValidCedula($digits) ? new self($digits, TaxIdKind::Cedula) : null,
            default => null,
        };
    }

    public static function isValid(?string $raw): bool
    {
        return self::tryParse($raw) !== null;
    }

    /**
     * RNC: suma ponderada de los 8 primeros dígitos, módulo 11.
     */
    private static function isValidRnc(string $digits): bool
    {
        $sum = 0;

        foreach (self::RNC_WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }

        $remainder = $sum % 11;

        $expected = match ($remainder) {
            0 => 2,
            1 => 1,
            default => 11 - $remainder,
        };

        return (int) $digits[8] === $expected;
    }

    /**
     * Cédula: algoritmo de Luhn (multiplicadores alternos 1 y 2) sobre los 10 primeros dígitos.
     */
    private static function isValidCedula(string $digits): bool
    {
        $sum = 0;

        for ($i = 0; $i < 10; $i++) {
            $product = (int) $digits[$i] * ($i % 2 === 0 ? 1 : 2);
            $sum += $product > 9 ? $product - 9 : $product;
        }

        $expected = (10 - $sum % 10) % 10;

        return (int) $digits[10] === $expected;
    }
}
