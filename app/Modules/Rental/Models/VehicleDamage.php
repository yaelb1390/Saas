<?php

declare(strict_types=1);

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Rental\Enums\DamageCategory;
use App\Modules\Rental\Enums\DamageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/** Un daño encontrado en el vehículo, que se puede cobrar o condonar. */
class VehicleDamage extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_rental_id',
        'vehicle_inspection_id',
        'category',
        'description',
        'amount',
        'responsible',
        'photo_path',
        'status',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => DamageCategory::class,
            'status' => DamageStatus::class,
            'amount' => 'decimal:2',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(VehicleRental::class, 'vehicle_rental_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
