<?php

declare(strict_types=1);

namespace App\Modules\Rental\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un abono o cargo sobre un alquiler.
 *
 * Que no pase del saldo se comprueba en el servicio, dentro de la transacción, no aquí: el saldo
 * pudo cambiar entre que se pintó la pantalla y se pulsó el botón.
 */
final class RegisterRentalPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_rentals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'in:cash,transfer,card,check'],
            'kind' => ['nullable', 'in:deposit,rental,extra_km,damage,fuel,other'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Falta el monto.',
            'amount.gt' => 'El monto tiene que ser mayor que cero.',
        ];
    }
}
