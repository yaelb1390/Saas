<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un abono sobre un trato financiado.
 *
 * Que no pase del saldo NO se comprueba aquí sino en el servicio: el saldo puede haber cambiado
 * entre que se pintó la pantalla y se pulsó el botón, y la comprobación buena es la que se hace
 * dentro de la transacción.
 */
final class RegisterDealPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_deals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'in:cash,transfer,card,check'],
            'reference' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'Falta el monto del abono.',
            'amount.gt' => 'El abono tiene que ser mayor que cero.',
        ];
    }
}
