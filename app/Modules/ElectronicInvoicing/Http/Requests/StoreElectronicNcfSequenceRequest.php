<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Requests;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use App\Modules\ElectronicInvoicing\Models\ElectronicNcfSequence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de un rango de e-NCF autorizado por la DGII.
 *
 * El rango no puede solaparse con otro de la misma empresa, ambiente y tipo: dos rangos que se
 * pisan entregarían el mismo e-NCF dos veces, y la DGII rechaza el segundo.
 */
final class StoreElectronicNcfSequenceRequest extends FormRequest
{
    /** El secuencial tiene 10 dígitos [IT §7]. */
    private const MAXIMO = 9999999999;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::enum(Environment::class)],
            'ecf_type' => ['required', 'integer', Rule::enum(EcfType::class)],
            'range_from' => ['required', 'integer', 'min:1', 'max:'.self::MAXIMO],
            'range_to' => ['required', 'integer', 'gte:range_from', 'max:'.self::MAXIMO],
            'authorized_at' => ['nullable', 'date', 'before_or_equal:today'],
            // Sin fecha = no vence (en pre-certificación las 32 y 34 no vencen [DT pp.6–7]).
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $seSolapa = ElectronicNcfSequence::query()
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->where('environment', $this->input('environment'))
                    ->where('ecf_type', (int) $this->input('ecf_type'))
                    ->where('range_from', '<=', (int) $this->input('range_to'))
                    ->where('range_to', '>=', (int) $this->input('range_from'))
                    ->exists();

                if ($seSolapa) {
                    $validator->errors()->add('range_from', 'Ese rango se cruza con otro ya registrado para el mismo tipo y ambiente: se repetirían números.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'environment' => 'ambiente',
            'ecf_type' => 'tipo de e-CF',
            'range_from' => 'desde',
            'range_to' => 'hasta',
            'authorized_at' => 'fecha de autorización',
            'expires_at' => 'fecha de vencimiento',
        ];
    }
}
