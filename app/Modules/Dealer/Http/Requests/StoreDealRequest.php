<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Abrir un trato sobre una unidad.
 *
 * Las reglas del financiamiento son condicionales a propósito: pedir cuotas y frecuencia siempre
 * obligaría a rellenarlas en una venta de contado, que es la mayoría.
 */
final class StoreDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vehicle_deals.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? 0;
        $deLaEmpresa = fn (string $tabla) => Rule::exists($tabla, 'id')
            ->where(fn ($q) => $q->where('company_id', $companyId));

        $financiado = $this->input('financing') === 'installments';

        return [
            'vehicle_id' => ['required', 'integer', $deLaEmpresa('vehicles')],
            'customer_id' => ['required', 'integer', $deLaEmpresa('customers')],
            'agreed_price' => ['required', 'numeric', 'min:0'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
            // El usado que entra en parte de pago tiene que ser OTRA unidad, no la que se vende.
            'trade_in_vehicle_id' => ['nullable', 'integer', 'different:vehicle_id', $deLaEmpresa('vehicles')],
            'trade_in_value' => ['nullable', 'numeric', 'min:0'],
            'financing' => ['nullable', 'in:none,installments'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'frequency' => [$financiado ? 'required' : 'nullable', 'in:weekly,biweekly,monthly'],
            // Un tope de 120: diez años de cuotas mensuales. Más que eso es un error de tecleo, y
            // sin tope alguien escribe 10000 y el sistema crea diez mil filas.
            'installments_count' => [$financiado ? 'required' : 'nullable', 'integer', 'min:1', 'max:120'],
            'start_date' => [$financiado ? 'required' : 'nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'close' => ['nullable', 'boolean'],
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
            'agreed_price.required' => 'Falta el precio pactado.',
            'trade_in_vehicle_id.different' => 'El vehículo recibido en parte de pago no puede ser el mismo que se vende.',
            'frequency.required' => 'Di cada cuánto va a pagar.',
            'installments_count.required' => 'Di en cuántas cuotas.',
            'installments_count.max' => 'Son demasiadas cuotas; revisa el número.',
            'start_date.required' => 'Falta la fecha de la primera cuota.',
        ];
    }
}
