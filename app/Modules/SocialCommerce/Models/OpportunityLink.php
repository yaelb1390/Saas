<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\CRM\Models\Opportunity;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De qué conversación/regla salió una oportunidad del CRM. Tabla puente: `Opportunity` no se
 * entera de que esto existe (auditoría, sección 16.1).
 *
 * @property int $opportunity_id
 * @property int|null $conversation_id
 * @property int|null $rule_id
 */
final class OpportunityLink extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $table = 'social_commerce_opportunity_links';

    protected $fillable = ['company_id', 'opportunity_id', 'conversation_id', 'rule_id'];

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
