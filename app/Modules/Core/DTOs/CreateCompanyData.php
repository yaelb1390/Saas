<?php

declare(strict_types=1);

namespace App\Modules\Core\DTOs;

/**
 * DTO inmutable para la creación de una empresa. Transporta datos ya validados desde la
 * capa HTTP (Form Request) hacia el servicio, sin acoplar el dominio al Request.
 */
final readonly class CreateCompanyData
{
    public function __construct(
        public string $name,
        public ?string $legalName = null,
        public ?string $taxId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public string $currency = 'DOP',
        public string $timezone = 'America/Santo_Domingo',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            legalName: $data['legal_name'] ?? null,
            taxId: $data['tax_id'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            address: $data['address'] ?? null,
            currency: $data['currency'] ?? 'DOP',
            timezone: $data['timezone'] ?? 'America/Santo_Domingo',
        );
    }
}
