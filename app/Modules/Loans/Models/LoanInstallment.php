<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Loans\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cuota del calendario de amortización de un préstamo. El importe a cobrar de la cuota es
 * amount + late_fee; lo pendiente es (amount + late_fee) − paid_amount.
 *
 * @property InstallmentStatus $status
 * @property string $amount
 * @property string $late_fee
 * @property string $paid_amount
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 */
class LoanInstallment extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'loan_id',
        'number',
        'due_date',
        'amount',
        'principal_portion',
        'interest_portion',
        'late_fee',
        'paid_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallmentStatus::class,
            'amount' => 'decimal:2',
            'principal_portion' => 'decimal:2',
            'interest_portion' => 'decimal:2',
            'late_fee' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'number' => 'integer',
            'due_date' => 'date',
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

    /** Total a cobrar de la cuota (capital+interés + mora). */
    public function due(): string
    {
        return bcadd((string) $this->amount, (string) $this->late_fee, 2);
    }

    /** Lo que resta por cobrar de la cuota, nunca negativo. */
    public function outstanding(): string
    {
        $rest = bcsub($this->due(), (string) $this->paid_amount, 2);

        return bccomp($rest, '0', 2) > 0 ? $rest : '0.00';
    }

    /**
     * Está vencida si aún debe algo y su fecha ya pasó. Es un cálculo, no una columna: así nunca
     * queda desactualizado respecto al calendario ni a la fecha de hoy.
     */
    public function isOverdue(): bool
    {
        return $this->status->canBePaid()
            && bccomp($this->outstanding(), '0', 2) > 0
            && $this->due_date->copy()->endOfDay()->isPast();
    }
}
