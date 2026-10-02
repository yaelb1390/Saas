<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Application;

/**
 * Lo que el servidor tiene que tener para poder generar, validar y firmar e-CF.
 *
 * Existe porque producción y local NO son iguales: producción (vercel-php) no tiene GD, y nadie ha
 * comprobado allí el resto. En vez de suponerlo, la pantalla de Facturación Electrónica lo pregunta
 * al propio servidor en el que corre.
 */
final class RuntimeRequirements
{
    /** Extensión => para qué hace falta. */
    private const EXTENSIONES = [
        'openssl' => 'Abrir el certificado .p12 y firmar el XML.',
        'dom' => 'Construir el XML del e-CF.',
        'libxml' => 'Validar el XML contra los esquemas oficiales de la DGII.',
        'bcmath' => 'Calcular impuestos y totales sin errores de redondeo.',
    ];

    /** @return array<int, array{extension: string, motivo: string, ok: bool}> */
    public function check(): array
    {
        $resultado = [];

        foreach (self::EXTENSIONES as $extension => $motivo) {
            $resultado[] = ['extension' => $extension, 'motivo' => $motivo, 'ok' => extension_loaded($extension)];
        }

        return $resultado;
    }

    public function allMet(): bool
    {
        return collect($this->check())->every(fn (array $r): bool => $r['ok']);
    }
}
