<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Dealer\Enums\DealFrequency;
use App\Modules\Dealer\Enums\DealStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * El trato sobre una unidad: apartarla o venderla.
 *
 * @property DealStatus $status
 * @property ?DealFrequency $frequency
 * @property string $agreed_price
 * @property string $balance
 */
class VehicleDeal extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'customer_id',
        'customer_name',
        'code',
        'agreed_price',
        'down_payment',
        'trade_in_vehicle_id',
        'trade_in_value',
        'financing',
        'interest_rate',
        'interest_amount',
        'frequency',
        'installments_count',
        'start_date',
        'balance',
        'status',
        'reserved_at',
        'closed_at',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            // Como en el resto del módulo: SQLite devuelve float y PostgreSQL string.
            'agreed_price' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'trade_in_value' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'frequency' => DealFrequency::class,
            'start_date' => 'date',
            'reserved_at' => 'datetime',
            'closed_at' => 'datetime',
            'installments_count' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * El cliente.
     *
     * `withTrashed`: un cliente archivado no puede dejar sin cliente a un trato ya firmado. Es el
     * mismo fallo que ya se corrigió en ventas, donde archivar al cliente tumbaba cualquier pantalla
     * que pintara su nombre.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** El carro que entregó en parte de pago, si lo hubo. */
    public function tradeIn(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'trade_in_vehicle_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(VehicleInstallment::class)->orderBy('number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VehicleDealPayment::class)->latest('paid_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function esFinanciado(): bool
    {
        return $this->financing === 'installments';
    }

    /**
     * Lo que el cliente pone de entrada: el inicial más lo que valga su carro usado.
     *
     * Van juntos porque para el saldo hacen lo mismo —bajan lo que queda debiendo—, aunque uno sea
     * dinero y el otro un vehículo.
     */
    public function entregado(): string
    {
        return bcadd($this->down_payment ?? '0', $this->trade_in_value ?? '0', 2);
    }

    /** Lo que queda por financiar antes de aplicarle el interés. */
    public function aFinanciar(): string
    {
        $resto = bcsub($this->agreed_price ?? '0', $this->entregado(), 2);

        return bccomp($resto, '0', 2) < 0 ? '0.00' : $resto;
    }
}
