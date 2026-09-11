<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una unidad concreta de un producto serializado: el teléfono con ESTE IMEI, no «un teléfono».
 *
 * POR QUÉ EXISTE, y por qué no rompe el stock de siempre: ver la migración `create_product_units`.
 * En una frase: el `stock` por cantidad sigue siendo la verdad de CUÁNTAS hay —y no se toca—, y esta
 * tabla añade CUÁLES son, encima. Cada unidad, al entrar o salir, mueve también ese contador por la
 * puerta de siempre (`StockService`), así que nunca puede descuadrar con él.
 *
 * NO SE BORRA UNA UNIDAD VENDIDA. Se marca `sold` y se queda: su historia —qué serie tenía, a quién
 * se le vendió y cuándo— es exactamente lo que hará falta el día que el cliente vuelva con la
 * garantía. Por eso, además, `SoftDeletes`.
 */
final class ProductUnit extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use SoftDeletes;

    /** Está y se puede vender. Es la única que cuenta como stock. */
    public const DISPONIBLE = 'available';

    /** Apartada para un cliente. */
    public const RESERVADA = 'reserved';

    /** Salió por una venta. Deja de contar como stock, pero conserva su historia. */
    public const VENDIDA = 'sold';

    /** Volvió tras una devolución. */
    public const DEVUELTA = 'returned';

    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'serial',
        'status',
        'condition',
        'color',
        'cost',
        'price',
        'notes',
        'received_at',
        'sold_at',
        'sale_id',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'received_at' => 'datetime',
            'sold_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function estaDisponible(): bool
    {
        return $this->status === self::DISPONIBLE;
    }

    /**
     * El precio con el que se vende esta unidad.
     *
     * El suyo propio si lo tiene —un usado no vale lo que un nuevo—, y si no, el del catálogo. No se
     * copia el del producto al crear la unidad justamente para que la que no tiene precio propio siga
     * al catálogo cuando este cambie, en vez de quedarse con uno congelado.
     */
    public function precioDeVenta(): string
    {
        return $this->price !== null ? (string) $this->price : (string) $this->product?->price;
    }
}
