<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Http\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Social\Enums\AutomationTrigger;
use App\Modules\SocialCommerce\Services\TemplateRenderer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule as ValidationRule;

/**
 * Lo que se manda al crear o cambiar una regla.
 *
 * Los topes de plantillas (6 por canal) y de mensaje (640/1000 con o sin botón) son los mismos que
 * impone Zernio para las respuestas automáticas — ver
 * `App\Modules\Social\Http\Requests\StoreAutomationRequest`, misma API por debajo.
 */
final class StoreRuleRequest extends FormRequest
{
    private const MAX_PLANTILLAS = 6;

    private const MAX_DM = 1000;

    private const MAX_DM_CON_BOTON = 640;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $topeDm = $this->llevaBoton() ? self::MAX_DM_CON_BOTON : self::MAX_DM;

        return [
            'name' => ['required', 'string', 'max:80'],
            'zernio_account_id' => ['required', 'string'],
            'trigger' => ['nullable', ValidationRule::in(['comment', 'story_reply'])],
            'post' => ['nullable', 'string', 'max:200'],
            'product_id' => ['required', 'integer'],
            'price_mode' => ['nullable', ValidationRule::in(['normal', 'promotional'])],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:50'],
            'match_mode' => ['nullable', ValidationRule::in(['word', 'exact', 'contains'])],
            'typo_tolerance' => ['sometimes', 'boolean'],
            'also_in_dms' => ['sometimes', 'boolean'],
            'follow_gate' => ['sometimes', 'boolean'],
            'dm_delay' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'button_title' => ['nullable', 'string', 'max:20'],
            'button_url' => ['nullable', 'required_with:button_title', 'url', 'max:2000'],
            'dm_templates' => ['required', 'array', 'min:1', 'max:'.self::MAX_PLANTILLAS],
            'dm_templates.*' => ['required', 'string', 'max:'.$topeDm],
            'public_templates' => ['sometimes', 'array', 'max:'.self::MAX_PLANTILLAS],
            'public_templates.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // El producto tiene que ser de la empresa activa. Product::find() pasa por el global
            // scope de BelongsToCompany, así que un identificador de otra empresa no aparece —no
            // es una comprobación de más, es la única que de verdad aísla por tenant aquí.
            if (Product::find($this->input('product_id')) === null) {
                $v->errors()->add('product_id', 'Ese producto no existe o no es de tu empresa.');
            }

            $disparador = AutomationTrigger::tryFrom((string) $this->input('trigger', '')) ?? AutomationTrigger::POR_OMISION;

            if ($this->boolean('also_in_dms') && ! $disparador->admiteRespuestaEnPrivados()) {
                $v->errors()->add('also_in_dms', 'En las historias esto ya funciona solo: si alguien te escribe esa palabra por privado, también le contesta.');
            }

            // Variables desconocidas en cualquier plantilla: se rechaza aquí y no al sincronizar
            // con Zernio, para que el motivo se lea en el formulario.
            $renderer = app(TemplateRenderer::class);

            foreach (array_merge($this->plantillas('dm_templates'), $this->plantillas('public_templates')) as $texto) {
                $desconocidas = $renderer->variablesDesconocidas($texto);

                if ($desconocidas !== []) {
                    $v->errors()->add('dm_templates', 'No reconocemos {'.implode('}, {', $desconocidas).'}. Las variables válidas son: {'.implode('}, {', TemplateRenderer::VARIABLES).'}.');

                    break;
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre', 'zernio_account_id' => 'cuenta', 'product_id' => 'producto',
            'keywords' => 'palabras clave', 'dm_templates' => 'mensajes privados',
            'button_title' => 'texto del botón', 'button_url' => 'enlace del botón',
        ];
    }

    public function llevaBoton(): bool
    {
        return filled($this->input('button_title'));
    }

    /**
     * Los textos no vacíos de un campo de plantillas repetibles.
     *
     * @return list<string>
     */
    public function plantillas(string $campo): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => trim((string) $v),
            (array) $this->input($campo, []),
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Los dos identificadores de la publicación elegida (`postId|platformPostId`), o ambos null.
     *
     * Se llama `publicacionElegida()` y no `post()`: ese nombre ya lo usa
     * `Illuminate\Http\Request::post()` para leer un parámetro del cuerpo, y declararlo de nuevo
     * aquí con una firma distinta rompe la clase entera con un error de compatibilidad — no lo
     * cazó ningún test hasta que una petición HTTP real llegó a instanciar este FormRequest.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function publicacionElegida(): array
    {
        [$postId, $platformPostId] = array_pad(explode('|', (string) $this->input('post', ''), 2), 2, null);

        return [filled($postId) ? $postId : null, filled($platformPostId) ? $platformPostId : null];
    }

    /**
     * Los datos de la regla, listos para `Rule::create()`/`->update()`.
     *
     * @return array<string, mixed>
     */
    public function paraRegla(): array
    {
        [$postId, $platformPostId] = $this->publicacionElegida();
        $disparador = AutomationTrigger::tryFrom((string) $this->input('trigger', '')) ?? AutomationTrigger::POR_OMISION;

        return [
            'name' => (string) $this->input('name'),
            'zernio_account_id' => (string) $this->input('zernio_account_id'),
            'trigger' => $disparador->value,
            'zernio_post_id' => $disparador === AutomationTrigger::Comment ? $postId : null,
            'platform_post_id' => $disparador === AutomationTrigger::Comment ? $platformPostId : null,
            'product_id' => (int) $this->input('product_id'),
            'price_mode' => (string) ($this->input('price_mode') ?: 'normal'),
            'keywords' => array_values(array_filter(array_map('trim', (array) $this->input('keywords', [])))),
            'match_mode' => (string) ($this->input('match_mode') ?: 'word'),
            'typo_tolerance' => $this->boolean('typo_tolerance'),
            'also_in_dms' => $this->boolean('also_in_dms'),
            'follow_gate' => $this->boolean('follow_gate'),
            'dm_delay_seconds' => (int) $this->input('dm_delay', 0),
            'button_title' => $this->llevaBoton() ? (string) $this->input('button_title') : null,
            'button_url' => $this->llevaBoton() ? (string) $this->input('button_url') : null,
        ];
    }
}
