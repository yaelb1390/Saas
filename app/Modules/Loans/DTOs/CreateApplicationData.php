<?php

declare(strict_types=1);

namespace App\Modules\Loans\DTOs;

use App\Modules\Loans\Enums\LoanFrequency;

/**
 * DTO inmutable para registrar una solicitud de préstamo.
 *
 * Los campos de dinero y plazo son deliberadamente los mismos que los de `CreateLoanData`: al
 * desembolsar, la solicitud se traduce a ese DTO sin inventar nada. Lo que sobra aquí —el destino
 * del dinero— es lo que se pregunta al recibirla y ya no cabe en un préstamo hecho.
 */
final readonly class CreateApplicationData
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
        public ?string $purpose = null,
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
            interestAmount: self::opcional($data['interest_amount'] ?? null),
            lateFeeRate: self::opcional($data['late_fee_rate'] ?? null),
            collateral: $data['collateral'] ?? null,
            purpose: $data['purpose'] ?? null,
            notes: $data['notes'] ?? null,
        );
    }

    /**
     * Un campo numérico que llega vacío del formulario es «no se indicó», no cero. La diferencia
     * importa: con `interest_amount = 0` el préstamo saldría sin interés en vez de calcularlo de la
     * tasa.
     */
    private static function opcional(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }
}
