<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Inventory\Enums\SelectionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Grupo de opciones reutilizable entre productos: «Tamaño», «Sabor», «Extras».
 */
class OptionGroup extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'selection_type',
        'is_required',
        'min_selections',
        'max_selections',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'selection_type' => SelectionType::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<Option, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group');
    }

    /** ¿Este grupo admite marcar varias opciones? */
    public function isMultiple(): bool
    {
        return $this->selection_type === SelectionType::Multiple;
    }

    /**
     * Cuántas opciones como máximo. En los de selección única siempre es 1, aunque la columna diga
     * otra cosa: el tipo manda sobre el límite, no al revés.
     */
    public function maxAllowed(): int
    {
        if (! $this->isMultiple()) {
            return 1;
        }

        return $this->max_selections ?? PHP_INT_MAX;
    }

    /** Cuántas opciones como mínimo. Un grupo obligatorio exige al menos una. */
    public function minRequired(): int
    {
        if (! $this->isMultiple()) {
            return $this->is_required ? 1 : 0;
        }

        return max($this->is_required ? 1 : 0, $this->min_selections);
    }
}
