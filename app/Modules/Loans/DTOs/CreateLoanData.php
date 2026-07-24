<?php

declare(strict_types=1);

namespace App\Modules\Loans\DTOs;

use App\Modules\Loans\Enums\LoanFrequency;

/**
 * DTO inmutable para crear un préstamo. El interés lo coloca el administrador: puede darlo como
 * tasa (%) —y el servicio calcula el monto— o escribir el monto de interés directo, que manda.
 */
final readonly class CreateLoanData
{
    public function __construct(
        public int $customerId,
        public string $principal,
        public int $installmentsCount,
        public LoanFrequency $frequency,
        public string $startDate,
        public string $interestRate = '0',
        public ?string $interestAmount = null,
        public ?string $lateFeeRate = null,
        public ?string $collateral = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerId: (int) $data['customer_id'],
            principal: (string) $data['principal'],
            installmentsCount: (int) $data['installments_count'],
            frequency: LoanFrequency::from((string) $data['frequency']),
            startDate: (string) $data['start_date'],
            interestRate: (string) ($data['interest_rate'] ?? '0'),
            interestAmount: isset($data['interest_amount']) && $data['interest_amount'] !== ''
                ? (string) $data['interest_amount']
                : null,
            lateFeeRate: isset($data['late_fee_rate']) && $data['late_fee_rate'] !== ''
                ? (string) $data['late_fee_rate']
                : null,
            collateral: $data['collateral'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }
}
