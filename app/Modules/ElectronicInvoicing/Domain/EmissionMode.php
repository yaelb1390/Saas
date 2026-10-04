<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * Cómo participa el e-CF en las ventas y facturas de una empresa.
 *
 *   apagado  todo como siempre: solo serie B.
 *   sombra   la serie B sigue siendo EL comprobante; en paralelo se genera un e-CF con la misma venta
 *            para probar el circuito con datos reales. Si el e-CF falla, la venta no se entera. Es el
 *            modo de los ambientes de pruebas y certificación: sus e-CF no tienen validez fiscal, así
 *            que no pueden sustituir a la serie B.
 *   real     el e-CF SUSTITUYE a la serie B. Solo en el ambiente de producción.
 */
enum EmissionMode: string
{
    case Apagado = 'apagado';
    case Sombra = 'sombra';
    case Real = 'real';

    public function label(): string
    {
        return match ($this) {
            self::Apagado => 'Apagado (solo serie B)',
            self::Sombra => 'En paralelo (serie B + e-CF de prueba)',
            self::Real => 'e-CF en lugar de la serie B',
        };
    }

    /** ¿Puede usarse en este ambiente? «Real» solo en producción; «sombra» nunca en producción. */
    public function allowedIn(Environment $env): bool
    {
        return match ($this) {
            self::Apagado => true,
            self::Sombra => ! $env->isFiscal(),
            self::Real => $env->isFiscal(),
        };
    }
}
