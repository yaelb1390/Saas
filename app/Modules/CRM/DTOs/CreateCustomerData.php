<?php

declare(strict_types=1);

namespace App\Modules\CRM\DTOs;

/**
 * DTO inmutable para crear un cliente.
 */
final readonly class CreateCustomerData
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $taxId = null,
        public ?string $cedula = null,
        public ?string $address = null,
        public ?string $notes = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->taxId,
            'cedula' => $this->cedula,
            'address' => $this->address,
            'notes' => $this->notes,
        ];
    }
}
