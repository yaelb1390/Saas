<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar las fechas de un alquiler, arrastrando o redimensionando en el calendario.
 *
 * Que no se solape con otra reserva NO se comprueba aquí: esa comprobación va dentro de la
 * transacción del servicio (`VehicleAvailabilityService`), porque entre que se suelta el evento en
 * el navegador y llega la petición, el vehículo pudo quedar reservado por otra persona.
 */
final class RescheduleRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_rentals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end_at.after' => 'La devolución tiene que ser después de la recogida.',
        ];
    }
}
