<?php

declare(strict_types=1);

namespace App\Modules\Rental\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una foto de una inspección (entrega o devolución). */
class VehicleInspectionPhoto extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_inspection_id',
        'path',
        'position',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(VehicleInspection::class, 'vehicle_inspection_id');
    }
}
