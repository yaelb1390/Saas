<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\HR\Models\Employee;
use App\Modules\Sales\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Venta. Al completarse descuenta stock del almacén. El vínculo con la sesión de caja
 * (cash_session_id) lo establece el módulo POS; Ventas no depende de Caja.
 *
 * @property SaleStatus $status
 * @property int $company_id
 * @property string $code
 * @property string $total
 */
class Sale extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'branch_id',
        'warehouse_id',
        'cash_session_id',
        'code',
        'status',
        'customer_name',
        'subtotal',
        'tax',
        'total',
        'tip',
        'discount_total',
        'paid',
        'change',
        'payment_method',
        'completed_at',
        'user_id',
        'employee_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'tip' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'paid' => 'decimal:2',
            'change' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Cliente del CRM al que se le vendió. Null en la venta de mostrador, que no identifica a
     * nadie; el nombre impreso en el recibo vive aparte, en customer_name.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Empleado que atendió la venta (POS de servicios). Null si no se registró.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
