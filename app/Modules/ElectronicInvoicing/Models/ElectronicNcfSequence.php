<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un rango de e-NCF autorizado por la DGII a una empresa, para un ambiente y un tipo.
 *
 * @property Environment $environment
 * @property EcfType $ecf_type
 * @property int $range_from
 * @property int $range_to
 * @property int $next_number
 * @property Carbon|null $expires_at
 * @property bool $is_active
 */
final class ElectronicNcfSequence extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'environment', 'ecf_type', 'range_from', 'range_to', 'next_number',
        'authorized_at', 'expires_at', 'is_active', 'created_by',
    ];

    public function hasAvailableNumbers(): bool
    {
        return $this->next_number <= $this->range_to;
    }

    /** Vence al terminar el día de `expires_at`; sin fecha, no vence. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->endOfDay()->isPast();
    }

    public function remaining(): int
    {
        return max(0, $this->range_to - $this->next_number + 1);
    }

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'ecf_type' => EcfType::class,
            'range_from' => 'integer',
            'range_to' => 'integer',
            'next_number' => 'integer',
            'authorized_at' => 'date',
            'expires_at' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
