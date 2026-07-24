<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abono/cobro de un préstamo. Registro inmutable que baja el saldo del préstamo y dispara el
 * ingreso en Finanzas.
 *
 * @property string $amount
 * @property string $balance_after
 */
class LoanPayment extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'loan_id',
        'amount',
        'balance_after',
        'paid_at',
        'method',
        'note',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
