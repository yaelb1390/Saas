<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\WhatsApp\Enums\MessageDirection;
use App\Modules\WhatsApp\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje de WhatsApp (entrante o saliente).
 *
 * @property MessageDirection $direction
 * @property MessageStatus $status
 */
class WaMessage extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $table = 'wa_messages';

    protected $fillable = [
        'company_id',
        'wa_conversation_id',
        'direction',
        'type',
        'body',
        'status',
        'external_id',
        'sent_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'status' => MessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WaConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WaConversation::class, 'wa_conversation_id');
    }
}
