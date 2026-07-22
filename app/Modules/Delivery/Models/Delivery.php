<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Entrega/reparto. Aislada por company_id.
 *
 * @property DeliveryStatus $status
 * @property Carbon|null $assigned_at
 * @property Carbon|null $delivered_at
 */
class Delivery extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'sale_id',
        'code',
        'status',
        'customer_name',
        'phone',
        'address',
        'driver_name',
        'assigned_at',
        'delivered_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Cliente del CRM que recibe la entrega. Null si no se identificó; el nombre y el teléfono
     * de contacto del reparto viven aparte, en customer_name y phone.
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
}
