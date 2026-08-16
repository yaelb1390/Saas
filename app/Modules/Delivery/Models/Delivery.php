<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryOutcomeReason;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\HR\Models\Employee;
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
 * `delivered_at` significa CUÁNDO SE CERRÓ, no cuándo se entregó: también se sella al marcarla
 * fallida o cancelada. Se dejó así en vez de añadir una columna que nadie consultaría, pero el
 * nombre engaña y por eso queda dicho aquí. Para «entregas del día» hay que filtrar además por
 * estado.
 *
 * @property DeliveryStatus $status
 * @property DeliveryOutcomeReason|null $outcome_reason
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
        'outcome_reason',
        'outcome_note',
        'customer_name',
        'phone',
        'address',
        'driver_name',
        'employee_id',
        'amount_to_collect',
        'assigned_at',
        'delivered_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'outcome_reason' => DeliveryOutcomeReason::class,
            'amount_to_collect' => 'decimal:2',
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
            'collected_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * El repartidor, ahora un empleado del sistema.
     *
     * `driver_name` sigue existiendo con su nombre copiado: si mañana se borra la ficha, la entrega
     * tiene que poder decir quién la llevó.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** ¿Hay que cobrarle algo al cliente en la puerta? */
    public function cobraEnLaPuerta(): bool
    {
        return bccomp((string) $this->amount_to_collect, '0', 2) > 0;
    }

    /** Dinero que el repartidor tiene encima por esta entrega. */
    public function pendienteDeLiquidar(): bool
    {
        return $this->collected_at !== null && $this->settled_at === null;
    }

    /** A quién se le entrega, venga del CRM o escrito a mano. */
    public function paraQuien(): string
    {
        return $this->customer_name ?? $this->customer?->name ?? 'Sin nombre';
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
