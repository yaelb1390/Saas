<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * Filtros del listado de inventario: búsqueda libre y «solo stock bajo».
     *
     * Vive en el modelo y no en el controlador porque lo usan DOS sitios que tienen que coincidir
     * exactamente: la pantalla que enseña los productos y el borrado múltiple de «seleccionar todos
     * los que coinciden». Si cada uno filtrara por su cuenta, alguien podría acabar borrando un
     * conjunto distinto del que está viendo.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeFiltered(Builder $query, ?string $texto = null, bool $soloStockBajo = false): void
    {
        $query
            ->when(filled($texto), fn (Builder $q) => $q->where(
                fn (Builder $sub) => $sub->whereLike('sku', "%{$texto}%")
                    ->orWhereLike('name', "%{$texto}%")
                    ->orWhereLike('barcode', "%{$texto}%")
            ))
            // Mismo umbral que la tarjeta «Stock bajo» del resumen.
            ->when($soloStockBajo, fn (Builder $q) => $q
                ->whereHas('stock', fn ($s) => $s->where('quantity', '<', 5)));
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
     * Grupos de opciones que ofrece este producto («Tamaño», «Sabor»...), en el orden en que se le
     * presentan al cajero.
     *
     * @return BelongsToMany<OptionGroup, $this>
     */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_group')
            ->withPivot(['company_id', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /**
     * Asigna los grupos de opciones del producto, en el orden recibido.
     *
     * Existe para que nadie tenga que acordarse del `company_id` de la tabla pivote, que es NOT NULL
     * y que `attach()` no rellena solo.
     *
     * Se resolvió así y no con `withPivotValue('company_id', $this->company_id)` en la relación
     * porque aquello revienta al PRECARGAR (`with('optionGroups')`): en ese momento Eloquent
     * construye la relación sobre una instancia sin atributos, `company_id` es null y lanza «The
     * provided value may not be null».
     *
     * @param  array<int, int>  $groupIds
     */
    public function syncOptionGroups(array $groupIds): void
    {
        $payload = [];

        foreach (array_values($groupIds) as $orden => $groupId) {
            $payload[$groupId] = [
                'company_id' => $this->company_id,
                'sort_order' => $orden,
            ];
        }

        $this->optionGroups()->sync($payload);
    }

    /**
     * Existencia total del producto sumando todos los almacenes.
     *
     * Si la relación ya viene precargada (rejilla del punto de venta, listados con `with('stock')`)
     * se suma en memoria. Sin esta rama, cada ficha lanzaría su propio SUM y pintar el catálogo
     * sería un N+1: precargar la relación no servía de nada porque este método la ignoraba.
     *
     * La suma va con bcmath a escala 3, la misma de la columna, para que ambos caminos devuelvan el
     * valor con idéntico formato y el payload no cambie según cómo se haya cargado el producto.
     */
    public function totalStock(): string
    {
        $total = $this->relationLoaded('stock')
            ? $this->stock->reduce(
                static fn (string $acc, Stock $row): string => bcadd($acc, (string) $row->quantity, 3),
                '0',
            )
            : (string) $this->stock()->sum('quantity');

        // Se fuerza la escala en ambos casos: la base devuelve «48» y la suma en memoria «48.000».
        // Sin normalizar, el mismo producto se serializaría distinto según cómo se hubiera cargado.
        return bcadd($total, '0', 3);
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
