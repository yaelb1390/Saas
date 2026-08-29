<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un abono del cliente sobre un trato financiado.
 *
 * Guarda CUÁNDO entró el dinero y por qué vía, que es lo que hay que poder enseñar cuando alguien
 * reclama. La cuota dice lo que se debe; esto dice lo que se pagó.
 */
class VehicleDealPayment extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_deal_id',
        'amount',
        'method',
        'reference',
        'paid_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(VehicleDeal::class, 'vehicle_deal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
