<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El punto que el repartidor guarda al llegar a la puerta.
 *
 * Lo manda el GPS del móvil, no una persona, y eso cambia qué hay que desconfiar: nadie va a teclear
 * una latitud de 200, pero un teléfono sin fijar posición sí manda cosas raras.
 */
final class SaveDeliveryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que la entrega sea SUYA se comprueba en el controlador, con la ficha de empleado: aquí
        // todavía no se sabe de qué entrega hablamos.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /*
             * EL (0, 0) SE RECHAZA.
             *
             * Es lo que manda un GPS que aún no ha fijado posición, y cae en el Atlántico frente a
             * África. Sin esta comprobación se guardaría como si fuera la casa del cliente, el
             * próximo repartidor abriría Waze apuntando al océano, y —lo peor— nadie sabría que el
             * punto está mal: se vería un pin, no un error.
             */
            $datos = $validator->getData();
            $latitud = $datos['latitude'] ?? null;
            $longitud = $datos['longitude'] ?? null;

            // Si ni siquiera llegaron, ya lo dijo «required»: no se pisa ese mensaje con otro.
            if (! is_numeric($latitud) || ! is_numeric($longitud)) {
                return;
            }

            if (abs((float) $latitud) < 0.0001 && abs((float) $longitud) < 0.0001) {
                $validator->errors()->add(
                    'latitude',
                    'Tu teléfono todavía no encuentra dónde estás. Sal a cielo abierto y vuelve a intentarlo.',
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'latitude' => 'latitud',
            'longitude' => 'longitud',
        ];
    }
}
