<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\SocialCommerce\Enums\TemplateChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una plantilla (principal o alternativa) de una regla. El cuerpo guarda las variables sin
 * resolver — {@see \App\Modules\SocialCommerce\Services\TemplateRenderer} las rellena al
 * sincronizar con Zernio.
 *
 * @property int $rule_id
 * @property string $channel
 * @property string $body
 * @property int $position
 */
final class RuleTemplate extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'social_commerce_rule_templates';

    protected $fillable = ['company_id', 'rule_id', 'channel', 'body', 'position'];

    protected function casts(): array
    {
        return [
            'channel' => TemplateChannel::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    public function esPrincipal(): bool
    {
        return $this->position === 0;
    }
}
