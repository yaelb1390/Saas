<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opción elegida en una línea de venta, con su nombre y recargo congelados.
 *
 * No lleva auditoría: es un registro inmutable que nace con la venta y no se edita nunca.
 */
class SaleItemOption extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sale_item_id',
        'option_id',
        'group_name',
        'option_name',
        'price_delta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Misma escala que el resto de columnas de dinero: sin el cast, la base devuelve «60» y el
        // recibo mostraría el recargo con un formato distinto al de los importes de su lado.
        return [
            'price_delta' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<SaleItem, $this>
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
