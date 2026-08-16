<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Requests;

use App\Modules\Social\Enums\KeywordMatch;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que se manda al crear o cambiar una respuesta automática.
 *
 * Aquí se aplican las reglas que la API impone de verdad —comprobadas contra ella, no leídas en el
 * manual— para que el cliente vea el motivo en su formulario y no un fallo genérico diez segundos
 * después:
 *
 *   · El mensaje privado baja de ~1000 a 640 caracteres cuando lleva botón.
 *   · «Responder también por privado» exige al menos una palabra clave: sin ninguna significaría
 *     «responde a cualquier mensaje», y Zernio lo rechaza.
 *   · Un botón sin dirección no sirve de nada.
 */
final class StoreAutomationRequest extends FormRequest
{
    /** Tope de Zernio cuando el mensaje lleva botones. */
    private const MAX_DM_CON_BOTON = 640;

    private const MAX_DM = 1000;

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
            'name' => ['required', 'string', 'max:80'],
            'account_id' => ['required', 'string'],
            // Vacío = en todas las publicaciones. Si viene, trae los DOS identificadores unidos por
            // «|»: el de Zernio y el de la red, que es lo que la API exige para apuntar a una sola.
            'post' => ['nullable', 'string', 'max:200'],
            'keywords' => ['required', 'string', 'max:500'],
            'match_mode' => ['nullable', Rule::enum(KeywordMatch::class)],
            'comment_reply' => ['nullable', 'string', 'max:500'],
            'dm_message' => ['required', 'string', 'max:'.($this->llevaBoton() ? self::MAX_DM_CON_BOTON : self::MAX_DM)],
            'button_title' => ['nullable', 'string', 'max:20'],
            // Un botón sin dirección es un botón que no lleva a ningún sitio: peor que no ponerlo,
            // porque el cliente lo pulsa y no pasa nada.
            'button_url' => ['nullable', 'required_with:button_title', 'url', 'max:2000'],
            'also_in_dms' => ['sometimes', 'boolean'],
            'follow_gate' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Sin palabras clave, «responder también por privado» contestaría a TODO mensaje que
            // entre. La API lo rechaza; se corta aquí para que el motivo se lea en el formulario.
            if ($this->boolean('also_in_dms') && $this->palabras() === []) {
                $v->errors()->add('keywords', 'Para responder también por privado hace falta al menos una palabra clave: sin ninguna contestaría a todos los mensajes.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'account_id' => 'cuenta', 'keywords' => 'palabras clave',
            'dm_message' => 'mensaje privado', 'comment_reply' => 'respuesta pública',
            'button_title' => 'texto del botón', 'button_url' => 'enlace del botón',
        ];
    }

    public function messages(): array
    {
        return [
            'button_url.required_with' => 'Un botón necesita a dónde llevar: pon el enlace.',
            'dm_message.max' => $this->llevaBoton()
                ? 'Con botón, el mensaje privado admite 640 caracteres como mucho.'
                : 'El mensaje privado admite 1000 caracteres como mucho.',
        ];
    }

    /**
     * Las palabras clave, separadas por comas y sin huecos.
     *
     * @return array<int, string>
     */
    public function palabras(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $p): string => trim($p),
            explode(',', (string) $this->input('keywords', '')),
        ), static fn (string $p): bool => $p !== ''));
    }

    public function llevaBoton(): bool
    {
        return filled($this->input('button_title'));
    }

    /**
     * El cuerpo tal como lo espera Zernio.
     *
     * Lo que no se ofrece en el formulario no se manda: la API acepta ausentes las variaciones, los
     * retrasos y las palabras excluidas, y mandarlos vacíos sería decirle que los queremos vacíos.
     *
     * @return array<string, mixed>
     */
    public function paraZernio(): array
    {
        $cuerpo = [
            'name' => (string) $this->input('name'),
            'accountId' => (string) $this->input('account_id'),
            'keywords' => $this->palabras(),
            // Por omisión la palabra suelta, NO la de la API: ver KeywordMatch.
            'matchMode' => (string) ($this->input('match_mode') ?: KeywordMatch::POR_OMISION->value),
            'dmMessage' => (string) $this->input('dm_message'),
            'alsoMatchInDms' => $this->boolean('also_in_dms'),
            'isActive' => $this->boolean('is_active'),
        ];

        if (filled($this->input('comment_reply'))) {
            $cuerpo['commentReply'] = (string) $this->input('comment_reply');
        }

        /*
         * Una publicación concreta, o ninguna.
         *
         * Ausente significa «en cualquier publicación», que es lo que quiere la mayoría: la
         * automatización sigue funcionando en las fotos que suba mañana. Mandar los campos vacíos NO
         * es lo mismo —sería pedir «la publicación con identificador vacío»— así que se omiten.
         *
         * Van los DOS identificadores porque la API pide el suyo (`postId`) además del de la red.
         */
        [$postId, $platformPostId] = array_pad(explode('|', (string) $this->input('post', ''), 2), 2, null);

        if (filled($postId) && filled($platformPostId)) {
            $cuerpo['postId'] = (string) $postId;
            $cuerpo['platformPostId'] = (string) $platformPostId;
        }

        if ($this->llevaBoton()) {
            $cuerpo['buttons'] = [[
                'type' => 'url',
                'title' => (string) $this->input('button_title'),
                'url' => (string) $this->input('button_url'),
            ]];
        }

        // Los textos del «solo a quien me siga» tienen valores por omisión sensatos en Zernio: se
        // manda el objeto vacío para encenderlo y no se piden en pantalla.
        if ($this->boolean('follow_gate')) {
            $cuerpo['followGate'] = new \stdClass;
        }

        return $cuerpo;
    }
}
