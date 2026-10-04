<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Providers;

/**
 * Lo que dice el directorio de la DGII de un contribuyente [DT pp.37–39]: si es receptor electrónico
 * y las direcciones de sus servicios (recepción, aprobación comercial y, opcional, autenticación).
 */
final readonly class ReceiverLookup
{
    public const FOUND = 'found';

    public const NOT_ELECTRONIC = 'not_electronic';

    /** Fallo pasajero (red, 5xx): se reintenta. */
    public const ERROR = 'error';

    /** El proveedor no ofrece esta operación (PSFE sin conectar, proveedor de prueba). */
    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        public string $status,
        public ?string $receptionUrl = null,
        public ?string $approvalUrl = null,
        public ?string $authUrl = null,
        public ?string $error = null,
    ) {}
}
