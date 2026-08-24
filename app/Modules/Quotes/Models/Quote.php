<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una cotización: lo que se le ofreció a un cliente, con su precio y su fecha de caducidad.
 *
 * Es un DOCUMENTO DEL PASADO, y de ahí salen casi todas sus reglas. El nombre y el teléfono del
 * cliente se copian en vez de leerse de la ficha, el texto de cada línea se copia en vez de leerse
 * del catálogo, y el precio se congela: lo que el cliente leyó aquel día tiene que seguir diciendo
 * lo mismo cuando vuelva con el papel en la mano.
 *
 * @property-read Collection<int, QuoteItem> $items
 */
class Quote extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'customer_id',
        'customer_name',
        'customer_phone',
        'status',
        'valid_until',
        'subtotal',
        'tax',
        'discount_total',
        'total',
        'notes',
        'sale_id',
        'sent_at',
        'accepted_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'valid_until' => 'date',
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** La venta que salió de esta cotización, si se convirtió. */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ¿Se le pasó la fecha?
     *
     * Se calcula, no se consulta el estado. Una cotización caduca por el paso del tiempo, no porque
     * alguien pase a marcarla: si dependiera de una tarea programada, el sábado por la mañana
     * seguirían saliendo como vigentes todas las que vencieron el viernes por la noche.
     */
    public function estaCaducada(): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        return $this->valid_until->endOfDay()->isPast();
    }

    /**
     * El estado que hay que ENSEÑAR, que no siempre es el guardado.
     *
     * Una enviada a la que se le pasó la fecha está caducada aunque en la base ponga «enviada».
     * Enseñar el guardado a secas haría que el vendedor la ofreciera como vigente.
     */
    public function estadoReal(): QuoteStatus
    {
        if ($this->status === QuoteStatus::Converted) {
            return QuoteStatus::Converted;
        }

        return $this->estaCaducada() && $this->status !== QuoteStatus::Rejected
            ? QuoteStatus::Expired
            : $this->status;
    }

    public function sePuedeConvertir(): bool
    {
        return $this->estadoReal()->sePuedeConvertir();
    }

    /** Cuántos días le quedan. Negativo si ya pasó; null si no caduca. */
    public function diasDeVigencia(): ?int
    {
        return $this->valid_until === null
            ? null
            : (int) Carbon::now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false);
    }
}
