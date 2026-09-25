<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de qué plantilla se usó y cuándo, inferido del webhook (ver la migración: no es un
 * mecanismo de control de la rotación, Zernio no lo permite).
 *
 * @property int|null $rule_id
 * @property int|null $rule_template_id
 * @property string $channel
 * @property string $matched_text
 * @property \Illuminate\Support\Carbon $used_at
 */
final class RuleTemplateUsage extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $table = 'social_commerce_rule_template_usage';

    protected $fillable = ['company_id', 'rule_id', 'rule_template_id', 'channel', 'matched_text', 'used_at'];

    protected function casts(): array
    {
        return [
            'channel' => TemplateChannel::class,
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    /**
     * @return BelongsTo<RuleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RuleTemplate::class, 'rule_template_id');
    }
}
