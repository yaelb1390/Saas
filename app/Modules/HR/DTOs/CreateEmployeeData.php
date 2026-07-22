<?php

declare(strict_types=1);

namespace App\Modules\HR\DTOs;

/**
 * DTO inmutable para contratar un empleado.
 */
final readonly class CreateEmployeeData
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $position = null,
        public ?string $salary = null,
        public ?int $userId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'position' => $this->position,
            'salary' => $this->salary,
            'user_id' => $this->userId,
            'hired_at' => now()->toDateString(),
            'is_active' => true,
        ];
    }
}
