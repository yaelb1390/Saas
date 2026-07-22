<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación de WhatsApp con un número. Se enlaza al cliente del CRM cuando coincide.
 */
class WaConversation extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'wa_conversations';

    protected $fillable = [
        'company_id',
        'customer_id',
        'phone',
        'name',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<WaMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class);
    }
}
