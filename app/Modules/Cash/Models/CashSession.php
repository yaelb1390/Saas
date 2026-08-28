<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Models\User;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Sesión (turno) de caja. Su saldo esperado se calcula al cerrar como el fondo de apertura
 * más la suma de los movimientos.
 *
 * @property CashSessionStatus $status
 */
class CashSession extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'cash_register_id',
        'warehouse_id',
        'user_id',
        'status',
        'opening_amount',
        'expected_amount',
        'counted_amount',
        'difference',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashSessionStatus::class,
            'opening_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    /**
     * @return BelongsTo<CashRegister, $this>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * El almacén del que sale lo que se cobra en este turno.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * De dónde se descuenta la mercancía, con red debajo.
     *
     * Cae al almacén de por omisión cuando la sesión no trae ninguno, y eso pasa en dos casos que no
     * son teóricos: las cajas que ya estaban abiertas cuando se aplicó la migración, y el hueco entre
     * que sale el código y alguien migra a mano —aquí el despliegue no corre migraciones—.
     *
     * Devolver null en vez de reventar es a propósito: quien llama ya sabe avisar de que no hay
     * almacén configurado, y ese mensaje le sirve al cajero. Una excepción aquí, no.
     */
    public function almacenDeSalida(): ?Warehouse
    {
        if (DbTable::tieneColumna('cash_sessions', 'warehouse_id') && $this->warehouse_id !== null) {
            $propio = $this->warehouse()->first();

            if ($propio !== null) {
                return $propio;
            }
        }

        return Warehouse::query()->where('is_default', true)->orderBy('id')->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
