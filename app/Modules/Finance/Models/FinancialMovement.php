<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Finance\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimiento financiero. Registro inmutable con importe con signo.
 *
 * @property MovementType $type
 */
class FinancialMovement extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'account_id',
        'type',
        'amount',
        'description',
        'reference_type',
        'reference_id',
        'occurred_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
