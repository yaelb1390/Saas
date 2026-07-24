<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Loans\Enums\LoanFrequency;
use App\Modules\Loans\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Préstamo informal: capital que la empresa presta a un cliente y cobra en cuotas. El interés lo
 * fija el administrador (tasa y/o monto); el sistema calcula total y cuota. El saldo (balance) baja
 * con cada abono. El estado "vencido" no se guarda: se deriva de las cuotas por fecha.
 *
 * @property LoanStatus $status
 * @property LoanFrequency $frequency
 * @property int $company_id
 * @property string $code
 * @property string $principal
 * @property string $total
 * @property string $balance
 */
class Loan extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'code',
        'customer_name',
        'principal',
        'interest_rate',
        'interest_amount',
        'total',
        'frequency',
        'installments_count',
        'installment_amount',
        'late_fee_rate',
        'start_date',
        'status',
        'balance',
        'collateral',
        'notes',
        'disbursed_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'frequency' => LoanFrequency::class,
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'late_fee_rate' => 'decimal:2',
            'balance' => 'decimal:2',
            'installments_count' => 'integer',
            'start_date' => 'date',
            'disbursed_at' => 'datetime',
        ];
    }

    /**
     * Cliente al que se le prestó.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<LoanInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('number');
    }

    /**
     * @return HasMany<LoanPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->latest('paid_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
