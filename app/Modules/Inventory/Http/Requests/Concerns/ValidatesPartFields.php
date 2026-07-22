<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Concerns;

/**
 * Reglas y etiquetas de los campos de repuesto de vehículo, compartidas por el alta y la edición de
 * productos. Todos opcionales: un producto genérico no los necesita.
 */
trait ValidatesPartFields
{
    /**
     * @return array<string, mixed>
     */
    protected function partFieldRules(): array
    {
        return [
            'part_number' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'vehicle_make' => ['nullable', 'string', 'max:100'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'year_from' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'year_to' => ['nullable', 'integer', 'min:1900', 'max:2100', 'gte:year_from'],
            'location' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function partFieldAttributes(): array
    {
        return [
            'part_number' => 'número de parte',
            'brand' => 'marca',
            'vehicle_make' => 'marca del vehículo',
            'vehicle_model' => 'modelo del vehículo',
            'year_from' => 'año desde',
            'year_to' => 'año hasta',
            'location' => 'ubicación',
        ];
    }
}
