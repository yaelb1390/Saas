<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Models\SocialWelcomeSetting;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El mensaje de bienvenida y sus variaciones.
 *
 * El tope de 900 caracteres es el de un privado de Instagram con margen. Se corta aquí para que el
 * cliente lo vea al escribir y no diez segundos después, cuando ya se lo rechazó la API.
 */
final class StoreWelcomeRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:900'],
            'variations' => ['sometimes', 'array', 'max:'.SocialWelcomeSetting::MAX_VARIACIONES],
            'variations.*' => ['nullable', 'string', 'max:900'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['message' => 'mensaje de bienvenida'];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Escribe el mensaje que va a recibir quien te escriba.',
            'message.max' => 'El mensaje admite 900 caracteres como mucho.',
        ];
    }

    /**
     * Las variaciones escritas, sin los huecos que deja el formulario.
     *
     * Se filtran los vacíos porque las casillas se añaden y se quitan en pantalla: si alguien abre
     * tres y solo escribe en una, guardar dos cadenas vacías haría que la bienvenida saliera en
     * blanco una de cada tres veces.
     *
     * @return list<string>
     */
    public function variaciones(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) $this->input('variations', []),
        ), static fn (string $v): bool => $v !== ''));
    }
}
