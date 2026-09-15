<?php

declare(strict_types=1);

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Rental\Enums\FuelLevel;
use App\Modules\Rental\Enums\InspectionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * El checklist de entrega o de devolución de un alquiler: kilometraje, combustible, estado y firma.
 */
class VehicleInspection extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_rental_id',
        'type',
        'mileage',
        'fuel_level',
        'exterior_condition',
        'interior_condition',
        'accessories',
        'checklist',
        'observations',
        'signature_path',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InspectionType::class,
            'fuel_level' => FuelLevel::class,
            'mileage' => 'integer',
            'checklist' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(VehicleRental::class, 'vehicle_rental_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(VehicleInspectionPhoto::class)->orderBy('position');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(VehicleDamage::class);
    }

    public function hasSignature(): bool
    {
        return $this->signature_path !== null && $this->signature_path !== '';
    }
}
