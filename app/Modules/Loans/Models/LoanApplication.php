<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Loans\Enums\LoanApplicationStatus;
use App\Modules\Loans\Enums\LoanFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Solicitud de préstamo: lo que el cliente pide, lo que se averigua de él y qué se decidió.
 *
 * Guarda por separado los términos SOLICITADOS y los APROBADOS, porque aprobar menos de lo pedido es
 * el caso normal y el expediente tiene que poder decir las dos cosas.
 *
 * Los indicadores de la evaluación —capacidad de pago y peso de la cuota— no son columnas: se
 * calculan al mirarlos. Es la misma decisión que ya tomó el módulo con el estado «vencido» de una
 * cuota, y por el mismo motivo: una cifra guardada envejece en cuanto alguien corrige un ingreso.
 *
 * @property LoanApplicationStatus $status
 * @property LoanFrequency $frequency
 * @property int $company_id
 * @property string $code
 * @property string $principal
 * @property int $installments_count
 * @property string $interest_rate
 * @property string|null $interest_amount
 * @property string|null $approved_principal
 * @property int|null $approved_installments_count
 * @property string|null $approved_interest_rate
 * @property string|null $monthly_income
 * @property string|null $monthly_expenses
 * @property string|null $other_debts
 * @property Carbon|null $decided_at
 * @property int|null $loan_id
 */
class LoanApplication extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    /** Escala de bcmath para el dinero, igual que en LoanService. */
    private const SCALE = 2;

    protected $fillable = [
        'company_id',
        'customer_id',
        'code',
        'customer_name',
        'principal',
        'interest_rate',
        'interest_amount',
        'frequency',
        'installments_count',
        'late_fee_rate',
        'start_date',
        'collateral',
        'purpose',
        'notes',
        'monthly_income',
        'monthly_expenses',
        'other_debts',
        'employment',
        'guarantor_name',
        'guarantor_phone',
        'guarantor_cedula',
        'evaluation_notes',
        'status',
        'approved_principal',
        'approved_installments_count',
        'approved_interest_rate',
        'decided_at',
        'decided_by',
        'decision_notes',
        'loan_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => LoanApplicationStatus::class,
            'frequency' => LoanFrequency::class,
            'principal' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'late_fee_rate' => 'decimal:2',
            'approved_principal' => 'decimal:2',
            'approved_interest_rate' => 'decimal:2',
            'monthly_income' => 'decimal:2',
            'monthly_expenses' => 'decimal:2',
            'other_debts' => 'decimal:2',
            'installments_count' => 'integer',
            'approved_installments_count' => 'integer',
            'start_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Préstamo que salió de esta solicitud. Nulo mientras no se haya desembolsado.
     *
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Quien la recibió.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quien la aprobó o rechazó. Es otra persona que quien la recibió en cuanto la agencia tiene más
     * de un empleado, y por eso son dos columnas y no una.
     *
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // ------------------------------------------------------------------ Términos que van a valer

    /** Capital que se desembolsaría: el aprobado si se ajustó, y si no el pedido. */
    public function capitalEfectivo(): string
    {
        return $this->normalizar($this->approved_principal ?? $this->principal);
    }

    /** Número de cuotas que valdría. */
    public function cuotasEfectivas(): int
    {
        return max(1, $this->approved_installments_count ?? $this->installments_count);
    }

    /** Tasa de interés que valdría. */
    public function tasaEfectiva(): string
    {
        return $this->normalizar($this->approved_interest_rate ?? $this->interest_rate);
    }

    /** ¿Se aprobó con términos distintos a los que pidió el cliente? */
    public function seAjustaronLosTerminos(): bool
    {
        return $this->approved_principal !== null
            || $this->approved_installments_count !== null
            || $this->approved_interest_rate !== null;
    }

    /**
     * Interés total del préstamo que saldría de aquí.
     *
     * Repite la regla de `LoanService::create()` —el monto escrito a mano manda sobre la tasa— para
     * poder enseñar la cuota ANTES de desembolsar. El cálculo bueno sigue siendo el del servicio;
     * este es el mismo, y si divergieran el que manda es aquel.
     */
    public function interesEstimado(): string
    {
        if ($this->interest_amount !== null) {
            return $this->normalizar($this->interest_amount);
        }

        return bcdiv(
            bcmul($this->capitalEfectivo(), $this->tasaEfectiva(), self::SCALE + 2),
            '100',
            self::SCALE,
        );
    }

    /** Total a devolver: capital + interés. */
    public function totalEstimado(): string
    {
        return bcadd($this->capitalEfectivo(), $this->interesEstimado(), self::SCALE);
    }

    /** Cuota que le tocaría pagar. Es la cifra que se compara con su capacidad. */
    public function cuotaEstimada(): string
    {
        return bcdiv($this->totalEstimado(), (string) $this->cuotasEfectivas(), self::SCALE);
    }

    // ------------------------------------------------------------------------------- Evaluación

    /**
     * Lo que le queda libre al mes: ingresos − gastos − cuotas de otras deudas.
     *
     * Devuelve null si no se ha declarado el ingreso, porque cero y «no lo sabemos» son cosas
     * distintas y enseñar «0.00» haría creer que se evaluó y no da.
     */
    public function capacidadDePago(): ?string
    {
        if ($this->monthly_income === null) {
            return null;
        }

        $libre = bcsub($this->normalizar($this->monthly_income), $this->normalizar($this->monthly_expenses ?? '0'), self::SCALE);

        return bcsub($libre, $this->normalizar($this->other_debts ?? '0'), self::SCALE);
    }

    /**
     * Qué porcentaje de lo que le sobra al mes se llevaría la cuota.
     *
     * Por debajo de 100 le da; por encima, no. No se convierte en un semáforo ni en una puntuación
     * de 0 a 100: un número inventado sin nada detrás invita a decidir por él en vez de mirar el
     * caso.
     *
     * Null cuando no hay ingresos declarados O cuando la capacidad es cero o negativa. Ese segundo
     * caso NO es «cero por ciento»: es que no le sobra nada, y dividir ahí sería dividir entre cero.
     * Para distinguirlo está `noLeSobraNada()`.
     */
    public function pesoDeLaCuota(): ?string
    {
        $capacidad = $this->capacidadDePago();

        if ($capacidad === null || bccomp($capacidad, '0', self::SCALE) <= 0) {
            return null;
        }

        return bcdiv(bcmul($this->cuotaEstimada(), '100', self::SCALE + 2), $capacidad, self::SCALE);
    }

    /** Se evaluó y el resultado es que no le queda nada libre al mes. */
    public function noLeSobraNada(): bool
    {
        $capacidad = $this->capacidadDePago();

        return $capacidad !== null && bccomp($capacidad, '0', self::SCALE) <= 0;
    }

    /** Normaliza a string con 2 decimales para bcmath, como hace LoanService. */
    private function normalizar(string|float|int|null $valor): string
    {
        $valor = (string) ($valor ?? '0');

        return bcadd($valor === '' ? '0' : $valor, '0', self::SCALE);
    }
}
