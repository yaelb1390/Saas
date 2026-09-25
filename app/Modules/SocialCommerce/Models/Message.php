<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\SocialCommerce\Enums\MessageDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje de una conversación de Instagram. Espejo de `WaMessage`, tabla propia.
 *
 * @property int $conversation_id
 * @property string $direction
 * @property string $body
 * @property string|null $zernio_message_id
 * @property \Illuminate\Support\Carbon $sent_at
 */
final class Message extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'social_commerce_messages';

    protected $fillable = ['company_id', 'conversation_id', 'direction', 'body', 'zernio_message_id', 'sent_at'];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
