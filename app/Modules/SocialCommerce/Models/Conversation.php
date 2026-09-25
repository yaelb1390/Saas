<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conversación de Instagram con una identidad de contacto. Espejo de `WaConversation`, tabla
 * propia (no toca el módulo WhatsApp).
 *
 * @property int $contact_identity_id
 * @property int|null $rule_id
 * @property string $zernio_conversation_id
 * @property string $zernio_account_id
 * @property string $platform
 */
final class Conversation extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'social_commerce_conversations';

    protected $fillable = [
        'company_id', 'contact_identity_id', 'rule_id', 'zernio_conversation_id',
        'zernio_account_id', 'platform', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ContactIdentity, $this>
     */
    public function contactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class);
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
