<?php

declare(strict_types=1);

namespace App\Modules\Help\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Una pregunta al asistente.
 *
 * El tope de 300 caracteres no es para ahorrar: es que una pregunta más larga que eso no es una
 * pregunta, es un texto pegado, y `HelpSearch` puntúa por palabras —con doscientas, todos los
 * artículos empatan y no acierta ninguno—.
 */
final class AskAssistantRequest extends FormRequest
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
            'pregunta' => ['required', 'string', 'min:3', 'max:300'],
        ];
    }

    public function attributes(): array
    {
        return ['pregunta' => 'pregunta'];
    }

    public function messages(): array
    {
        return [
            'pregunta.required' => 'Escribe tu pregunta.',
            'pregunta.min' => 'Escribe un poco más para poder buscarlo.',
            'pregunta.max' => 'La pregunta es muy larga: resúmela en una frase.',
        ];
    }
}
