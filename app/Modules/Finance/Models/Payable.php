<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Finance\Enums\PayableStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Cuenta por pagar: lo que el negocio todavía debe a un proveedor. El saldo (balance) baja con
 * cada abono. El estado "vencida" no se guarda: se deriva de la fecha.
 *
 * @property PayableStatus $status
 * @property string $total
 * @property string $balance
 * @property ?Carbon $due_date
 */
class Payable extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'supplier_id',
        'supplier_name',
        'purchase_order_id',
        'total',
        'balance',
        'due_date',
        'status',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PayableStatus::class,
            'total' => 'decimal:2',
            'balance' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * La orden de compra de la que salió esta deuda, si nació de una automáticamente.
     *
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<PayablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class)->latest('paid_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Cuánto se ha pagado, en porcentaje del total (0-100). Para el anillo de progreso. */
    public function porcentajePagado(): int
    {
        if (bccomp((string) $this->total, '0', 2) <= 0) {
            return 0;
        }

        $pagado = bcsub((string) $this->total, (string) $this->balance, 2);

        return (int) round(((float) $pagado / (float) $this->total) * 100);
    }

    /** ¿Se le pasó la fecha y todavía se debe algo? */
    public function estaVencida(): bool
    {
        if ($this->due_date === null || $this->status === PayableStatus::Paid) {
            return false;
        }

        return $this->due_date->endOfDay()->isPast();
    }
}
