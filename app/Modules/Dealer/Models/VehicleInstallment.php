<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Dealer\Enums\DealInstallmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuota del financiamiento propio del dealer.
 *
 * @property DealInstallmentStatus $status
 */
class VehicleInstallment extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_deal_id',
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
            'status' => DealInstallmentStatus::class,
            /*
             * `decimal:2` y no dejarlo crudo: SQLite devuelve los decimales como float y PostgreSQL
             * como string. Sin el cast, bcmath revienta en los tests y en producción no, que es la
             * peor combinación posible —el fallo solo aparecería donde no se está mirando—.
             */
            'amount' => 'decimal:2',
            'principal_portion' => 'decimal:2',
            'interest_portion' => 'decimal:2',
            'late_fee' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(VehicleDeal::class, 'vehicle_deal_id');
    }

    /**
     * Lo que falta por pagar de esta cuota, mora incluida.
     *
     * La mora se suma a lo que se debe: si no, un recargo puesto por el administrador nunca se
     * cobraría y la cuota se daría por saldada con menos dinero del que se pidió.
     */
    public function outstanding(): string
    {
        $debe = bcadd($this->amount ?? '0', $this->late_fee ?? '0', 2);

        return bcsub($debe, $this->paid_amount ?? '0', 2);
    }

    /** Vencida: pasó su fecha y todavía debe algo. */
    public function vencida(): bool
    {
        return $this->status !== DealInstallmentStatus::Paid
            && $this->due_date !== null
            && $this->due_date->isPast();
    }
}
