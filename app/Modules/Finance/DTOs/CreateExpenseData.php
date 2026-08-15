<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTOs;

/**
 * DTO inmutable para anotar un gasto.
 *
 * No lleva método de pago: lo dice la cuenta de la que sale. Lo único que el método añadiría de
 * verdad —el número del cheque o de la transferencia— es `reference`.
 */
final readonly class CreateExpenseData
{
    public function __construct(
        public int $accountId,
        public int $categoryId,
        public string $amount,
        public string $description,
        public string $paidAt,
        public ?int $supplierId = null,
        public ?string $supplierName = null,
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accountId: (int) $data['account_id'],
            categoryId: (int) $data['expense_category_id'],
            amount: (string) $data['amount'],
            description: (string) $data['description'],
            paidAt: (string) $data['paid_at'],
            supplierId: isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
            supplierName: self::opcional($data['supplier_name'] ?? null),
            reference: self::opcional($data['reference'] ?? null),
            notes: self::opcional($data['notes'] ?? null),
        );
    }

    private static function opcional(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (string) $valor;
    }
}
