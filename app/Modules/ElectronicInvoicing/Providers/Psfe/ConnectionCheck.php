<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

/**
 * Resultado de «Probar conexión»: si el proveedor aceptó las credenciales, un mensaje para quien
 * conecta y, si el proveedor lo dice, a qué cuenta corresponden (para que se vea que es la suya).
 */
final readonly class ConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $account = null,
    ) {}
}
