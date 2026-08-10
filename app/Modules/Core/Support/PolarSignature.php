<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

/**
 * Verifica la firma de los webhooks de Polar (especificación «Standard Webhooks»).
 *
 * Es la única prueba de que un aviso de pago viene de verdad de Polar. Sin ella, cualquiera que
 * conociera la URL podría activarse la suscripción que quisiera con un simple POST.
 *
 * Cómo funciona: Polar envía tres cabeceras (`webhook-id`, `webhook-timestamp`, `webhook-signature`)
 * y firma la cadena «id.timestamp.cuerpo» con HMAC-SHA256, en base64.
 *
 * Se comprueban DOS cosas, no una:
 *  1. Que la firma cuadre  -> el mensaje no fue alterado ni inventado.
 *  2. Que el sello de tiempo sea reciente -> un aviso legítimo capturado no se puede reenviar
 *     indefinidamente para renovar una suscripción gratis (ataque de repetición).
 */
final class PolarSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $tolerance = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            secret: (string) config('polar.webhook_secret'),
            tolerance: (int) config('polar.webhook_tolerance', 300),
        );
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    /**
     * ¿El aviso viene de Polar y es reciente?
     *
     * @param  string  $payload  El cuerpo CRUDO de la petición. Tiene que ser el original byte a
     *                           byte: si se decodifica y se vuelve a codificar el JSON, el orden o
     *                           el espaciado cambian y la firma deja de cuadrar.
     */
    public function verify(string $id, string $timestamp, string $signatureHeader, string $payload): bool
    {
        if (! $this->isConfigured() || $id === '' || $signatureHeader === '') {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $signed = $id.'.'.$timestamp.'.'.$payload;

        foreach ($this->keys() as $key) {
            $expected = base64_encode(hash_hmac('sha256', $signed, $key, binary: true));

            foreach ($this->providedSignatures($signatureHeader) as $provided) {
                // hash_equals compara en tiempo constante: no revela por cuántos caracteres falló.
                if (hash_equals($expected, $provided)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * El sello de tiempo (segundos Unix) debe caer dentro del margen, en ambos sentidos: uno muy
     * antiguo es una repetición, y uno del futuro delata un reloj manipulado.
     */
    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $this->tolerance;
    }

    /**
     * Claves candidatas para el HMAC.
     *
     * La especificación dice que el secreto viaja en base64 con el prefijo `whsec_`, pero no todos
     * los paneles lo entregan así. Se prueban las dos formas para que un secreto copiado tal cual
     * del panel de Polar funcione sin que haya que adivinar su codificación. Esto no debilita nada:
     * quien no conozca el secreto sigue sin poder firmar de ninguna de las dos maneras.
     *
     * @return array<int, string>
     */
    private function keys(): array
    {
        $secret = $this->secret;

        if (str_starts_with($secret, 'whsec_')) {
            $secret = substr($secret, 6);
        }

        $keys = [];
        $decoded = base64_decode($secret, strict: true);

        if ($decoded !== false && $decoded !== '') {
            $keys[] = $decoded;
        }

        $keys[] = $secret;

        return $keys;
    }

    /**
     * Firmas recibidas. La cabecera admite varias separadas por espacios (así se rota el secreto
     * sin cortar el servicio: durante la rotación llegan la vieja y la nueva). Cada una viene como
     * «v1,<firma>»; se ignoran versiones que no sean v1.
     *
     * @return array<int, string>
     */
    private function providedSignatures(string $header): array
    {
        $signatures = [];

        foreach (explode(' ', $header) as $part) {
            $piece = explode(',', trim($part), 2);

            if (count($piece) === 2 && $piece[0] === 'v1' && $piece[1] !== '') {
                $signatures[] = $piece[1];
            }
        }

        return $signatures;
    }
}
