<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Sales\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una forma de pago de una venta.
 *
 * LA INVARIANTE que sostiene el cajón, la contabilidad y el 607: la suma de `amount` de una venta es
 * exactamente su `total`, y el vuelto nunca está dentro. Hoy se sostiene sola porque una venta
 * completada es inmutable; **el día que exista editar una venta o devolver parte de ella, habrá que
 * rehacer también su desglose** o el 607 empezará a mentir sin que nada falle.
 *
 * `amount` es lo imputado a esta vía; `tendered` es lo que el cliente entregó. Solo se diferencian
 * en efectivo, que es la única vía que admite vuelto: un datáfono no lo da.
 */
class SalePayment extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sale_id',
        'method',
        'amount',
        'tendered',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            /*
             * `decimal:2` y no float. PostgreSQL devuelve los decimales como cadena y SQLite como
             * número: sin el molde, el mismo importe se compararía distinto en los tests que en
             * producción, y en dinero eso no se puede.
             */
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
