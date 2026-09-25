<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Inventory\Models\Product;
use App\Modules\SocialCommerce\Enums\PriceMode;
use App\Modules\SocialCommerce\Enums\RuleStatus;
use App\Models\User;
use Database\Factories\SocialCommerce\RuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La regla palabra clave → producto → precio → plantilla.
 *
 * No decide sola cuándo responder: eso lo hace Zernio, una vez sincronizada (ver
 * `App\Modules\SocialCommerce\Services\RuleSyncService`). Este modelo es la configuración y el
 * estado de esa sincronización.
 *
 * @property string $name
 * @property string $zernio_account_id
 * @property string $trigger
 * @property string|null $zernio_post_id
 * @property string|null $platform_post_id
 * @property int $product_id
 * @property array<int, string> $keywords
 * @property string $match_mode
 * @property bool $typo_tolerance
 * @property bool $also_in_dms
 * @property bool $follow_gate
 * @property int $dm_delay_seconds
 * @property string|null $button_title
 * @property string|null $button_url
 * @property RuleStatus $status
 * @property string|null $zernio_automation_id
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $sync_error
 */
final class Rule extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'social_commerce_rules';

    /**
     * Se dice a mano por el mismo motivo que `Customer::newFactory()`: Laravel buscaría la
     * factory en `Database\Factories\Modules\SocialCommerce\Models\`, y vive en
     * `Database\Factories\SocialCommerce\` en su lugar.
     */
    protected static function newFactory(): RuleFactory
    {
        return RuleFactory::new();
    }

    protected $fillable = [
        'company_id', 'name', 'zernio_account_id', 'trigger', 'zernio_post_id', 'platform_post_id',
        'product_id', 'price_mode', 'keywords', 'match_mode', 'typo_tolerance', 'also_in_dms',
        'follow_gate', 'dm_delay_seconds', 'button_title', 'button_url', 'status', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'typo_tolerance' => 'boolean',
            'also_in_dms' => 'boolean',
            'follow_gate' => 'boolean',
            'dm_delay_seconds' => 'integer',
            'status' => RuleStatus::class,
            'price_mode' => PriceMode::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RuleTemplate, $this>
     */
    public function templates(): HasMany
    {
        return $this->hasMany(RuleTemplate::class, 'rule_id');
    }

    /**
     * @return HasMany<RuleTemplate, $this>
     */
    public function dmTemplates(): HasMany
    {
        return $this->templates()->where('channel', 'dm')->orderBy('position');
    }

    /**
     * @return HasMany<RuleTemplate, $this>
     */
    public function publicTemplates(): HasMany
    {
        return $this->templates()->where('channel', 'public')->orderBy('position');
    }

    /**
     * @return HasMany<RuleTemplateUsage, $this>
     */
    public function templateUsage(): HasMany
    {
        return $this->hasMany(RuleTemplateUsage::class, 'rule_id');
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'rule_id');
    }

    public function estaSincronizada(): bool
    {
        return filled($this->zernio_automation_id);
    }
}
