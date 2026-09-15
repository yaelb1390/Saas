<?php

declare(strict_types=1);

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Rental\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un abono o cargo sobre un alquiler. Mismo papel que `VehicleDealPayment` en el Dealer: guarda
 * cuándo entró el dinero y por qué vía; el saldo del alquiler dice lo que falta.
 */
class VehicleRentalPayment extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_rental_id',
        'amount',
        'method',
        'kind',
        'reference',
        'paid_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'kind' => PaymentKind::class,
            'paid_at' => 'datetime',
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
}
