<?php

declare(strict_types=1);

namespace App\Modules\Loans\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de la evaluación: con qué vive el cliente y quién responde por él.
 *
 * Todo es opcional a propósito. La evaluación se llena en tandas —los ingresos hoy, la cédula del
 * garante cuando el cliente la traiga— y un formulario que no deja guardar a medias termina relleno
 * de ceros para poder pasar, que es peor que estar vacío: un cero declarado se lee como un dato.
 */
final class EvaluateApplicationRequest extends FormRequest
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
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'monthly_expenses' => ['nullable', 'numeric', 'min:0'],
            'other_debts' => ['nullable', 'numeric', 'min:0'],
            'employment' => ['nullable', 'string', 'max:255'],
            'guarantor_name' => ['nullable', 'string', 'max:255'],
            'guarantor_phone' => ['nullable', 'string', 'max:50'],
            'guarantor_cedula' => ['nullable', 'string', 'max:20'],
            'evaluation_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'monthly_income' => 'ingreso mensual',
            'monthly_expenses' => 'gastos mensuales',
            'other_debts' => 'otras deudas',
            'employment' => 'ocupación',
            'guarantor_name' => 'nombre del garante',
            'guarantor_phone' => 'teléfono del garante',
            'guarantor_cedula' => 'cédula del garante',
            'evaluation_notes' => 'notas de la evaluación',
        ];
    }
}
