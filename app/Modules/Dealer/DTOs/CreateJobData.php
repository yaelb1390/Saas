<?php

declare(strict_types=1);

namespace App\Modules\Dealer\DTOs;

/** Un trabajo de preparación sobre una unidad. */
final readonly class CreateJobData
{
    public function __construct(
        public int $vehicleId,
        public string $description,
        public string $cost = '0',
        public ?string $performedBy = null,
        public ?string $performedAt = null,
        public bool $done = false,
        public ?string $notes = null,
    ) {}
}
