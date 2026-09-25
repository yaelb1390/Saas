<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Models;

use App\Modules\CRM\Models\Customer;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une un identificador de Instagram con, opcionalmente, un `Customer` del CRM. No modifica
 * `Customer`: solo lo referencia (ver la migración).
 *
 * @property int|null $customer_id
 * @property string $channel
 * @property string $external_id
 * @property string|null $external_username
 * @property string|null $display_name
 */
final class ContactIdentity extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'social_commerce_contact_identities';

    protected $fillable = [
        'company_id', 'customer_id', 'channel', 'external_id', 'external_username',
        'display_name', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'contact_identity_id');
    }
}
