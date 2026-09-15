<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reservar un vehículo.
 *
 * Que las fechas no se solapen con otra reserva NO se comprueba aquí: esa comprobación tiene que
 * hacerse DENTRO de la transacción del servicio (`VehicleAvailabilityService`), porque entre que se
 * valida la petición y se guarda, otra persona pudo reservar el mismo vehículo.
 */
final class StoreRentalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_rentals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;
        $deLaEmpresa = fn (string $tabla) => Rule::exists($tabla, 'id')
            ->where(fn ($q) => $q->where('company_id', $companyId));

        return [
            'vehicle_id' => ['required', 'integer', $deLaEmpresa('vehicles')],
            'customer_id' => ['required', 'integer', $deLaEmpresa('customers')],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Elige el vehículo.',
            'vehicle_id.exists' => 'Ese vehículo no es de tu empresa.',
            'customer_id.required' => 'Elige el cliente.',
            'customer_id.exists' => 'Ese cliente no es de tu empresa.',
            'start_at.required' => 'Falta la fecha de recogida.',
            'end_at.required' => 'Falta la fecha de devolución.',
            'end_at.after' => 'La devolución tiene que ser después de la recogida.',
        ];
    }
}
