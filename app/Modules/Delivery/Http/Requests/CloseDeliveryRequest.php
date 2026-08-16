<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Requests;

use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que manda el repartidor al cerrar una entrega.
 *
 * El estado NO viaja en la petición: viaja el motivo, y el estado se deduce de él. Aceptar los dos
 * permitiría cerrar como «entregada» algo cuyo motivo dice «no estaba nadie», y ese desacuerdo no
 * saltaría hasta cuadrar la caja.
 */
final class CloseDeliveryRequest extends FormRequest
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
            'reason' => ['required', Rule::enum(DeliveryOutcomeReason::class)],
            // La nota es opcional a propósito: de pie en la calle y con una mano, exigir que escriba
            // algo es exigir que no cierre la entrega.
            'note' => ['nullable', 'string', 'max:500'],
            'collected' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'motivo', 'note' => 'nota'];
    }
}
