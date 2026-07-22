<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Modules\Billing\Enums\CancellationReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\NcfType;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Factura fiscal con NCF. Puede originarse de una venta.
 *
 * @property NcfType $type
 * @property InvoiceStatus $status
 * @property CancellationReason|null $cancellation_code
 */
class Invoice extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'sale_id',
        'fiscal_sequence_id',
        'ncf',
        'type',
        'customer_name',
        'customer_tax_id',
        'subtotal',
        'tax',
        'total',
        'status',
        'cancellation_code',
        'cancellation_note',
        'cancelled_at',
        'cancelled_by',
        'issued_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => NcfType::class,
            'status' => InvoiceStatus::class,
            'cancellation_code' => CancellationReason::class,
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === InvoiceStatus::Cancelled;
    }

    /**
     * Cliente del CRM al que se facturó. Null si la factura no lo identificó (consumo final).
     * El nombre y el RNC impresos en el documento fiscal viven aparte, en customer_name y
     * customer_tax_id: son un snapshot y no cambian aunque se corrija la ficha del CRM.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<FiscalSequence, $this>
     */
    public function fiscalSequence(): BelongsTo
    {
        return $this->belongsTo(FiscalSequence::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
