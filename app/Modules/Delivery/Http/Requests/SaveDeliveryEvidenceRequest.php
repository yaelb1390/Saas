<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La foto que el repartidor hace al entregar.
 *
 * Sale de la cámara del móvil, así que llega grande y en cualquier formato que dispare el teléfono.
 * El tope de 12 MB es generoso a propósito —un móvil moderno pasa de 5 MB sin esfuerzo— porque el
 * recorte se hace DESPUÉS, en el servidor: rechazarla por tamaño obligaría al repartidor a repetirla
 * sin saber qué hacer distinto, de pie en la puerta de un cliente.
 */
final class SaveDeliveryEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que la entrega sea SUYA se comprueba en el controlador, con la ficha de empleado.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `image` y no solo `file`: impide colar un ejecutable con nombre de foto.
            'evidence' => ['required', 'image', 'max:12288'],
        ];
    }

    public function messages(): array
    {
        return [
            'evidence.required' => 'Hace falta la foto.',
            'evidence.image' => 'Eso no es una foto. Vuelve a tomarla con la cámara.',
            'evidence.max' => 'La foto pesa demasiado. Tómala otra vez con menos calidad.',
        ];
    }

    public function attributes(): array
    {
        return [
            'evidence' => 'foto de la entrega',
        ];
    }
}
