<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la cotización.
 *
 * Puede llevar producto o no llevarlo: la mano de obra, la instalación y el transporte se cotizan
 * igual que un tornillo y no están —ni tienen por qué estar— en el catálogo.
 */
class QuoteItem extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'quote_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * El importe de la línea: cantidad × precio, menos el descuento. Nunca negativo.
     *
     * Se calcula aquí y se guarda en `subtotal` para que el documento no dependa de que alguien
     * vuelva a hacer la cuenta igual dentro de seis meses.
     */
    public static function importe(string $cantidad, string $precio, string $descuento): string
    {
        $bruto = bcsub(bcmul($cantidad, $precio, 2), $descuento, 2);

        return bccomp($bruto, '0', 2) < 0 ? '0.00' : $bruto;
    }
}
