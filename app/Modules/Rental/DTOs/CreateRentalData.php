<?php

declare(strict_types=1);

namespace App\Modules\Rental\DTOs;

/** La reserva que se abre sobre un vehículo. */
final readonly class CreateRentalData
{
    public function __construct(
        public int $vehicleId,
        public int $customerId,
        public string $startAt,
        public string $endAt,
        public string $discount = '0',
        public ?string $depositOverride = null,
        public ?string $notes = null,
        // Si queda confirmada de una vez, o solo reservada. Una reserva confirmada bloquea la fecha
        // igual que una pendiente: lo que cambia es si ya se validó con el cliente.
        public bool $confirm = false,
    ) {}
}
