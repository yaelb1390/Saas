<?php

declare(strict_types=1);

namespace App\Modules\Dealer\DTOs;

/**
 * El trato que se abre sobre una unidad.
 *
 * `installmentsCount` e `interestRate` solo tienen sentido si `financing` es «installments». Se
 * validan en la petición, no aquí: un DTO que se defiende solo acabaría duplicando reglas que ya
 * están en el Form Request.
 */
final readonly class CreateDealData
{
    public function __construct(
        public int $vehicleId,
        public int $customerId,
        public string $agreedPrice,
        public string $downPayment = '0',
        public ?int $tradeInVehicleId = null,
        public string $tradeInValue = '0',
        public string $financing = 'none',
        public string $interestRate = '0',
        public ?string $interestAmount = null,
        public ?string $frequency = null,
        public int $installmentsCount = 0,
        public ?string $startDate = null,
        public ?string $notes = null,
        // Si se cierra en el acto o queda apartado. Un apartado bloquea la unidad igual: lo que
        // cambia es si ya se entregó.
        public bool $close = false,
    ) {}
}
