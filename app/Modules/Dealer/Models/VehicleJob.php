<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Dealer\Enums\ExpenseType;
use App\Modules\Dealer\Enums\JobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un trabajo de preparación sobre una unidad: pintura, gomas, papeles.
 *
 * Su costo es lo que convierte el precio de compra en el costo real. Sin esto el margen de la
 * pantalla sería una cifra bonita y falsa, que es peor que no tener cifra.
 */
class VehicleJob extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'type',
        'description',
        'cost',
        'performed_by',
        'status',
        'performed_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'type' => ExpenseType::class,
            'cost' => 'decimal:2',
            'performed_at' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
