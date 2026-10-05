<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers\Psfe;

/**
 * Un dato que el proveedor pide para conectarse (clave de API, usuario, id de cuenta…).
 *
 * Lo declara cada conector, no la pantalla: así el formulario y la validación salen solos al añadir
 * un proveedor nuevo. `secret` = se guarda cifrado y no se vuelve a enseñar nunca.
 */
final readonly class PsfeField
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $secret = false,
        public bool $required = true,
        public ?string $help = null,
        public int $maxLength = 500,
    ) {}
}
