<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Domain;

/**
 * Emisor o comprador de un e-CF, con los datos que pueden ir en el XML. Todo opcional salvo lo que
 * el tipo de comprobante exija (lo comprueba `EcfDocumentValidator`).
 */
final readonly class EcfParty
{
    public function __construct(
        public ?string $taxId = null,
        public ?string $legalName = null,
        public ?string $tradeName = null,
        public ?string $address = null,
        public ?string $municipality = null,
        public ?string $province = null,
        public ?string $email = null,
    ) {}
}
