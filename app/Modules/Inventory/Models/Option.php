<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una opción concreta: «2 bolas» (+60), «Chocolate» (+0), «Queso extra» (+40).
 */
class Option extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'option_group_id',
        'name',
        'price_delta',
        'linked_product_id',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Misma escala que `products.price` y que el recargo congelado en la línea de venta:
            // sin el cast, la base devuelve «60» y el POS pintaría «+RD$ 60» junto a precios con dos
            // decimales.
            'price_delta' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}
