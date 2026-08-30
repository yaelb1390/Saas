<?php

declare(strict_types=1);

namespace App\Modules\Dealer\DTOs;

/** Un trabajo de preparación sobre una unidad. */
final readonly class CreateJobData
{
    public function __construct(
        public int $vehicleId,
        public string $description,
        // En qué se gastó. TODOS los tipos entran en el costo real: separarlos sirve para saber
        // en qué se va el dinero, no para dejar alguno fuera de la suma.
        public string $type = 'reparacion',
        public string $cost = '0',
        public ?string $performedBy = null,
        public ?string $performedAt = null,
        public bool $done = false,
        public ?string $notes = null,
    ) {}
}
