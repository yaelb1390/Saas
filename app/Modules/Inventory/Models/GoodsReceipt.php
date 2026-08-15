<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una remesa que entró al almacén: qué llegó, cuándo y de quién.
 *
 * @property string $code
 * @property Carbon $received_at
 */
class GoodsReceipt extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'warehouse_id',
        'supplier_id',
        'supplier_name',
        'reference',
        'received_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
        ];
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** De quién vino, venga de la ficha del proveedor o escrito a mano. */
    public function deQuien(): string
    {
        return $this->supplier?->name ?? $this->supplier_name ?? 'Sin proveedor';
    }

    /** Unidades totales que entraron. Suma con bcmath: la existencia lleva 3 decimales. */
    public function unidades(): string
    {
        return $this->lines->reduce(
            static fn (string $suma, GoodsReceiptLine $l): string => bcadd($suma, (string) $l->quantity, 3),
            '0.000',
        );
    }

    /**
     * Lo que costó la remesa. Solo cuenta las líneas con costo: si nadie lo escribió, el total es
     * cero y no una cifra inventada a partir del costo antiguo del producto.
     */
    public function costoTotal(): string
    {
        return $this->lines->reduce(
            static fn (string $suma, GoodsReceiptLine $l): string => $l->unit_cost === null
                ? $suma
                : bcadd($suma, bcmul((string) $l->quantity, (string) $l->unit_cost, 2), 2),
            '0.00',
        );
    }
}
