<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Producto del catálogo. El stock se lleva por almacén (relación stock()).
 */
class Product extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'category_id',
        'sku',
        'name',
        'description',
        'image_path',
        'barcode',
        'part_number',
        'brand',
        'vehicle_make',
        'vehicle_model',
        'year_from',
        'year_to',
        'location',
        'unit',
        'cost',
        'price',
        'track_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'year_from' => 'integer',
            'year_to' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Compatibilidad de vehículo en una línea legible («Toyota Corolla 2015-2020»), o null si la
     * pieza no declara vehículo. Se usa en el mostrador y en los recibos.
     */
    public function vehicleFit(): ?string
    {
        $parts = array_filter([$this->vehicle_make, $this->vehicle_model]);

        if ($parts === []) {
            return null;
        }

        $years = match (true) {
            $this->year_from && $this->year_to => " {$this->year_from}-{$this->year_to}",
            (bool) $this->year_from => " {$this->year_from}+",
            (bool) $this->year_to => " hasta {$this->year_to}",
            default => '',
        };

        return implode(' ', $parts).$years;
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<Stock, $this>
     */
    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Existencia total del producto sumando todos los almacenes.
     */
    public function totalStock(): string
    {
        return (string) $this->stock()->sum('quantity');
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null && $this->image_path !== '';
    }

    /**
     * URL para mostrar la foto (o null si no tiene). Lleva ?v={timestamp} para que, al reemplazar la
     * imagen, el navegador no siga mostrando la vieja cacheada.
     */
    public function imageUrl(): ?string
    {
        if (! $this->hasImage()) {
            return null;
        }

        $version = $this->updated_at !== null ? $this->updated_at->timestamp : 0;

        return route('panel.products.image', $this).'?v='.$version;
    }
}
