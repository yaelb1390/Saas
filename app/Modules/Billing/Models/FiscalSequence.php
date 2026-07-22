<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Secuencia de NCF autorizada por la DGII para un tipo de comprobante.
 *
 * @property NcfType $type
 * @property Carbon|null $expires_at
 * @property int $next_number
 * @property int $range_to
 * @property int $number_length
 */
class FiscalSequence extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'type',
        'next_number',
        'range_from',
        'range_to',
        'number_length',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => NcfType::class,
            'next_number' => 'integer',
            'range_from' => 'integer',
            'range_to' => 'integer',
            'number_length' => 'integer',
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function hasAvailableNumbers(): bool
    {
        return $this->next_number <= $this->range_to;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Formatea un NCF: prefijo del tipo + número con ceros a la izquierda.
     */
    public function formatNcf(int $number): string
    {
        return $this->type->value.str_pad((string) $number, $this->number_length, '0', STR_PAD_LEFT);
    }
}
