<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Support;

use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Services\TemplateRenderer;

/**
 * Arma el cuerpo que `ZernioClient::createAutomation()`/`updateAutomation()` esperan, a partir de
 * una regla ya rellenada con su producto.
 *
 * Mismo contrato que `App\Modules\Social\Http\Requests\StoreAutomationRequest::paraZernio()` —no
 * es casualidad, es la misma API—, pero los textos salen de renderizar las plantillas contra el
 * producto en vez de venir ya escritos del formulario.
 */
final class ZernioAutomationPayload
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Rule $rule): array
    {
        $product = $rule->product;
        $company = $rule->company;

        $dm = $rule->dmTemplates()->get()
            ->map(fn ($t): string => $this->renderer->render($t->body, $product, $company))
            ->values();

        $publicas = $rule->publicTemplates()->get()
            ->map(fn ($t): string => $this->renderer->render($t->body, $product, $company))
            ->values();

        $cuerpo = [
            'name' => $rule->name,
            'accountId' => $rule->zernio_account_id,
            'trigger' => $rule->trigger,
            'keywords' => $rule->keywords,
            'matchMode' => $rule->match_mode,
            'dmMessage' => (string) ($dm->first() ?? ''),
            'alsoMatchInDms' => $rule->also_in_dms,
            'isActive' => $rule->status === RuleStatus::Active,
        ];

        if ($publicas->isNotEmpty()) {
            $cuerpo['commentReply'] = $publicas->first();
        }

        // La API solo aplica la tolerancia a erratas en «palabra suelta»; mandarla con los otros
        // modos no hace nada (mismo comentario que StoreAutomationRequest::paraZernio()).
        if ($rule->match_mode === 'word') {
            $cuerpo['typoTolerance'] = $rule->typo_tolerance;
        }

        if ($dm->count() > 1) {
            $cuerpo['dmMessageVariations'] = $dm->slice(1)->values()->all();
        }

        if ($publicas->count() > 1) {
            $cuerpo['commentReplyVariations'] = $publicas->slice(1)->values()->all();
        }

        if ($rule->dm_delay_seconds > 0) {
            $cuerpo['dmDelaySeconds'] = $rule->dm_delay_seconds;
        }

        // Las historias no cuelgan de una publicación: mandar ahí el identificador de una foto
        // ataría la automatización a algo que no existe como historia.
        if ($rule->trigger === 'comment' && filled($rule->zernio_post_id) && filled($rule->platform_post_id)) {
            $cuerpo['postId'] = $rule->zernio_post_id;
            $cuerpo['platformPostId'] = $rule->platform_post_id;
        }

        if (filled($rule->button_title)) {
            $cuerpo['buttons'] = [[
                'type' => 'url',
                'title' => $rule->button_title,
                'url' => $rule->button_url,
            ]];
        }

        if ($rule->follow_gate) {
            $cuerpo['followGate'] = new \stdClass;
        }

        return $cuerpo;
    }
}
