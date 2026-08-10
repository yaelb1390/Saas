<?php

declare(strict_types=1);

namespace App\Modules\POS\Models;

use App\Models\User;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido aparcado a la espera de cobro.
 *
 * No descuenta stock ni reserva nada: hasta que se cobra, la mercancía sigue disponible para
 * cualquier otro cliente. Reservarla sería peor que no hacerlo — un pedido olvidado dejaría producto
 * bloqueado sin que nadie sepa por qué.
 */
class HeldOrder extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'cash_session_id',
        'user_id',
        'reference',
        'customer_name',
        'payload',
        'total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }
}
