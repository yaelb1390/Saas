<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una salida de dinero ya pagada.
 *
 * El gasto es el HECHO; el apunte en la cuenta y, si salió del cajón, el apunte de caja son sus
 * CONSECUENCIAS. Los dos se enlazan con la relación polimórfica que ya usan las ventas y los
 * préstamos, así que no hacen falta columnas nuevas para encontrarlos: `movement()` y
 * `cashMovement()` los recuperan, y al anular el gasto son los que hay que deshacer.
 *
 * @property int $company_id
 * @property string $code
 * @property string $amount
 * @property string $description
 * @property Carbon $paid_at
 */
class Expense extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'account_id',
        'expense_category_id',
        'supplier_id',
        'supplier_name',
        'amount',
        'description',
        'reference',
        'paid_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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

    /**
     * El apunte que este gasto dejó en la cuenta.
     *
     * @return MorphOne<FinancialMovement, $this>
     */
    public function movement(): MorphOne
    {
        return $this->morphOne(FinancialMovement::class, 'reference');
    }

    /**
     * El apunte del cajón, solo si se pagó en efectivo con un turno abierto.
     *
     * @return MorphOne<CashMovement, $this>
     */
    public function cashMovement(): MorphOne
    {
        return $this->morphOne(CashMovement::class, 'reference');
    }

    /** A quién se le pagó, venga de la ficha del proveedor o escrito a mano. */
    public function aQuien(): string
    {
        return $this->supplier?->name ?? $this->supplier_name ?? '—';
    }
}
