<?php

declare(strict_types=1);

namespace App\Modules\Dealer\DTOs;

use Illuminate\Http\UploadedFile;

/**
 * Los datos de una unidad que entra al patio.
 *
 * Casi todo es opcional a propósito: un carro llega y hay que registrarlo YA, antes de que alguien
 * copie el chasis o mida el kilometraje. Exigir la ficha completa haría que se anotara en un papel
 * —y el papel no está en el sistema—.
 */
final readonly class CreateVehicleData
{
    public function __construct(
        public string $make,
        public string $model,
        public ?int $year = null,
        public ?string $vin = null,
        public ?string $trim = null,
        public ?string $color = null,
        public ?int $mileage = null,
        public ?string $fuel = null,
        public ?string $transmission = null,
        public ?string $plate = null,
        public string $purchaseCost = '0',
        public string $askingPrice = '0',
        public ?int $branchId = null,
        public ?string $acquiredAt = null,
        public ?string $notes = null,
        // El fichero subido, si lo hubo. Se guarda DESPUÉS de crear la unidad, porque el nombre
        // del fichero se cuelga de ella.
        public ?UploadedFile $photo = null,
    ) {}
}
