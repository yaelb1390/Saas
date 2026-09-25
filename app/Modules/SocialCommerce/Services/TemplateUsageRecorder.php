<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Modules\SocialCommerce\Models\Rule;
use App\Modules\SocialCommerce\Models\RuleTemplateUsage;
use Illuminate\Support\Carbon;

/**
 * Registra qué plantilla se usó, comparando un mensaje SALIENTE contra las plantillas de las
 * reglas activas de la empresa. No decide nada: es un reporte de lo que Zernio ya hizo (ver
 * SOCIAL_COMMERCE_META_RESEARCH.md — la rotación no es controlable desde aquí).
 *
 * Solo fiable para el mensaje privado: el webhook `message.received` es del buzón de
 * conversaciones, y una respuesta pública bajo un comentario no necesariamente pasa por ahí. Se
 * comprueba igual contra las plantillas públicas por si Zernio las enruta por el mismo aviso, pero
 * no hay garantía de la API para ese caso — de ahí que esto sea un reporte de mejor esfuerzo, no
 * una fuente de verdad.
 */
final class TemplateUsageRecorder
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function record(Company $company, string $texto, Carbon $usedAt): void
    {
        $texto = trim($texto);

        if ($texto === '') {
            return;
        }

        foreach (Rule::where('status', RuleStatus::Active)->get() as $rule) {
            $producto = $rule->product;

            if ($producto === null) {
                continue;
            }

            foreach (['dmTemplates', 'publicTemplates'] as $relacion) {
                foreach ($rule->{$relacion}()->get() as $plantilla) {
                    if (trim($this->renderer->render($plantilla->body, $producto, $company)) !== $texto) {
                        continue;
                    }

                    RuleTemplateUsage::create([
                        'company_id' => $company->id,
                        'rule_id' => $rule->id,
                        'rule_template_id' => $plantilla->id,
                        'channel' => $plantilla->channel,
                        'matched_text' => $texto,
                        'used_at' => $usedAt,
                    ]);

                    return;
                }
            }
        }
    }
}
