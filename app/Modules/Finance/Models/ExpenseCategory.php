<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Concepto de gasto: alquiler, luz, nómina, transporte...
 *
 * @property int $company_id
 * @property string $name
 * @property bool $is_active
 */
class ExpenseCategory extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /**
     * Conceptos con los que arranca una empresa nueva.
     *
     * No es una lista cerrada —se editan y se añaden—, pero tiene que existir desde el minuto uno:
     * si para anotar la factura de la luz hubiera que crear antes la categoría «Luz», todo el mundo
     * acabaría escribiendo sus gastos en la primera que encuentre y el informe por concepto no
     * serviría para nada.
     *
     * @var list<string>
     */
    public const INICIALES = [
        'Alquiler',
        'Luz',
        'Agua',
        'Teléfono e internet',
        'Nómina',
        'Transporte y combustible',
        'Mercancía',
        'Mantenimiento y reparaciones',
        'Publicidad',
        'Impuestos y tasas',
        'Comisiones bancarias',
        'Otros',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Las que se ofrecen al anotar un gasto.
     *
     * Desactivar una categoría la retira de los formularios pero NO toca los gastos que ya la usan:
     * el informe del año pasado tiene que seguir diciendo lo mismo.
     *
     * @param  Builder<ExpenseCategory>  $query
     */
    public function scopeUsables(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('name');
    }
}
