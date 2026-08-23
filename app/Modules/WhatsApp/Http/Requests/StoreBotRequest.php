<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Requests;

use App\Modules\WhatsApp\Models\WaBotSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el dueño le cuenta al bot sobre su negocio.
 *
 * Este texto es lo ÚNICO que el bot puede decirle a un cliente además del catálogo, así que las
 * validaciones no son burocracia: lo que entre aquí es lo que el negocio va a tener que cumplir.
 */
final class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Configurar lo que el bot promete a los clientes es administrar la línea, no escribir en
        // ella: el mismo permiso que vincula y desvincula.
        return $this->user()?->can('whatsapp.connect') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Cambiar de vía no rompe nada de lo guardado: el texto del negocio, el saludo y las
            // conversaciones son los mismos. Lo único que cambia es por dónde salen los mensajes.
            'provider' => ['required', Rule::in(WaBotSetting::VIAS)],

            'is_active' => ['boolean'],

            /*
             * Obligatorio SI se enciende, y esa es la regla que de verdad importa.
             *
             * Un bot encendido sin nada que contar no se queda callado: contesta «esa no te la sé»
             * a todo el mundo y pasa cada conversación a una persona. Desde fuera parece que está
             * roto, y desde dentro parece que está encendido. Mejor no dejar llegar ahí.
             */
            'business_info' => [
                $this->boolean('is_active') ? 'required' : 'nullable',
                'string',
                'max:'.WaBotSetting::MAX_INFO,
            ],

            // Cabe en la burbuja de un WhatsApp sin que haya que desplegarla.
            'greeting' => ['nullable', 'string', 'max:500'],

            /*
             * El papel que juega. NO es obligatorio: sin él, el bot sigue contestando con los datos
             * del negocio, que es como funcionaba antes.
             *
             * Y por largo que sea lo que se escriba aquí, no puede desactivar las reglas de no
             * inventar ni de no prometer: van después en el prompt y dicen que mandan sobre esto.
             */
            'instructions' => ['nullable', 'string', 'max:'.WaBotSetting::MAX_INSTRUCCIONES],

            'uses_documents' => ['boolean'],
            'includes_plans' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_info.required' => 'Para encender el bot tienes que contarle primero de qué va tu negocio: sin eso no sabría qué contestar.',
            'business_info.max' => 'La información del negocio no puede pasar de :max caracteres.',
            'greeting.max' => 'El saludo no puede pasar de :max caracteres.',
            'instructions.max' => 'Las instrucciones no pueden pasar de :max caracteres. Si necesitas contar mucho más, súbelo como documento en Administración › IA.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'business_info' => 'información del negocio',
            'greeting' => 'saludo',
            'instructions' => 'instrucciones',
        ];
    }
}
