<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La decisión: aprobar (con o sin ajuste de términos) o rechazar.
 *
 * Los tres campos de términos van vacíos cuando se aprueba lo que se pidió. Se validan igual que en
 * el alta —capital mayor que cero, cuotas de 1 a 1000— porque un ajuste a la baja mal tecleado sale
 * de la caja igual de rápido que uno bien tecleado.
 */
final class DecideApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'principal' => ['nullable', 'numeric', 'gt:0'],
            'installments_count' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'principal' => 'capital aprobado',
            'installments_count' => 'número de cuotas aprobado',
            'interest_rate' => 'tasa aprobada',
            'notes' => 'motivo',
        ];
    }
}
