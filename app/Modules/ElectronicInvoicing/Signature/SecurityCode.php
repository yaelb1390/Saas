<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Signature;

use InvalidArgumentException;

/**
 * Código de seguridad del e-CF: va en el QR, impreso bajo el QR y en las consultas a la DGII.
 *
 * Definición oficial: «los primeros seis (6) dígitos del hash generado en el SignatureValue»
 * [DT p.28; IT p.36]. Implementado como los primeros N caracteres del SignatureValue, que es la única
 * lectura compatible con el ejemplo oficial («dcp79q»). Es una INTERPRETACIÓN pendiente de confirmar en
 * pre-certificación: por eso la estrategia está en config/ecf.php y no fija en el código.
 */
final class SecurityCode
{
    public static function fromSignatureValue(string $signatureValue): string
    {
        $estrategia = (string) config('ecf.security_code.strategy', 'signature_value_prefix');
        $largo = (int) config('ecf.security_code.length', 6);

        if ($estrategia !== 'signature_value_prefix') {
            throw new InvalidArgumentException("Estrategia de código de seguridad no implementada: {$estrategia}.");
        }

        if (strlen($signatureValue) < $largo) {
            throw new InvalidArgumentException('SignatureValue demasiado corto para obtener el código de seguridad.');
        }

        return substr($signatureValue, 0, $largo);
    }
}
