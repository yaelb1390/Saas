<?php

declare(strict_types=1);

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\Models\Vehicle;
use App\Modules\Rental\Enums\InspectionType;
use App\Modules\Rental\Enums\RentalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un alquiler: reserva→entrega→en curso→devolución→cerrado, todo en una fila que avanza de estado.
 *
 * @property RentalStatus $status
 * @property string $total
 * @property string $balance
 */
class VehicleRental extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'customer_id',
        'code',
        'start_at',
        'end_at',
        'actual_pickup_at',
        'actual_return_at',
        'daily_rate',
        'days',
        'discount',
        'deposit_amount',
        'extra_km_charge',
        'fuel_charge',
        'damage_charge',
        'subtotal',
        'total',
        'balance',
        'status',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => RentalStatus::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'actual_pickup_at' => 'datetime',
            'actual_return_at' => 'datetime',
            'daily_rate' => 'decimal:2',
            'discount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'extra_km_charge' => 'decimal:2',
            'fuel_charge' => 'decimal:2',
            'damage_charge' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'balance' => 'decimal:2',
            'days' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * El cliente. `withTrashed`: uno archivado no puede dejar sin cliente a un alquiler ya cerrado,
     * mismo criterio que ya usa `VehicleDeal::customer()` en el Dealer.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VehicleRentalPayment::class)->latest('paid_at');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(VehicleInspection::class);
    }

    public function damages(): HasMany
    {
        return $this->hasMany(VehicleDamage::class);
    }

    public function pickupInspection(): ?VehicleInspection
    {
        return $this->inspections->firstWhere('type', InspectionType::Pickup);
    }

    public function returnInspection(): ?VehicleInspection
    {
        return $this->inspections->firstWhere('type', InspectionType::Return);
    }

    /**
     * Kilómetros recorridos: devolución menos entrega. Null mientras falte cualquiera de las dos
     * inspecciones —no hay nada que restar todavía—.
     */
    public function kilometersUsed(): ?int
    {
        $salida = $this->pickupInspection();
        $entrada = $this->returnInspection();

        if ($salida === null || $entrada === null) {
            return null;
        }

        return max(0, $entrada->mileage - $salida->mileage);
    }

    /** Cuánto se ha pagado en total, sumando todos los abonos y cargos. */
    public function paid(): string
    {
        return bcsub($this->total ?? '0', $this->balance ?? '0', 2);
    }
}
