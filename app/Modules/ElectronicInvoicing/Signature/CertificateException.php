<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use RuntimeException;

/** Mensajes para el usuario. Nunca incluyen la contraseña ni la clave. */
final class CertificateException extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self('No se pudo abrir el certificado: el archivo no es un .p12/.pfx válido o la contraseña no corresponde.');
    }

    public static function keyMismatch(): self
    {
        return new self('La clave privada del archivo no corresponde a su certificado.');
    }

    public static function expired(string $hasta): self
    {
        return new self("El certificado venció el {$hasta}. Solicita uno nuevo a tu prestadora de servicios de confianza.");
    }

    public static function notYetValid(string $desde): self
    {
        return new self("El certificado todavía no es válido (empieza el {$desde}).");
    }

    public static function missing(): self
    {
        return new self('La empresa no tiene un certificado digital activo. Súbelo en Facturación Electrónica → Certificado.');
    }

    public static function storage(): self
    {
        return new self('No se pudo leer el certificado guardado. Vuelve a subirlo.');
    }
}
