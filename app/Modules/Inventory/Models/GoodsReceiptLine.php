<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la remesa: qué producto entró, cuánto y a qué costo.
 *
 * @property string $quantity
 * @property string|null $unit_cost
 * @property string|null $previous_cost
 * @property bool $cost_updated
 */
class GoodsReceiptLine extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'goods_receipt_id',
        'product_id',
        'quantity',
        'unit_cost',
        'cost_updated',
        'previous_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'previous_cost' => 'decimal:2',
            'cost_updated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Lo que costó esta línea entera, o null si no se anotó el costo. */
    public function importe(): ?string
    {
        return $this->unit_cost === null
            ? null
            : bcmul((string) $this->quantity, (string) $this->unit_cost, 2);
    }
}
