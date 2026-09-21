<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuántas veces sufrió UNA empresa un error.
 *
 * `error_events` guarda el error una sola vez, agrupado por huella, con el total de veces que ocurrió. Esta
 * tabla guarda el desglose: una fila por (error, empresa). Sin ella, un mismo fallo que afecta a doce
 * empresas se veía como «148 veces» sin poder decir a quiénes ni cuántas a cada una, y lo que sabía del
 * tenant se perdía en cada repetición.
 *
 * NO lleva `BelongsToCompany`, igual que `ErrorEvent`: la lee el operador de la plataforma, que necesita
 * ver todas las empresas a la vez.
 *
 * @property int $error_event_id
 * @property int $company_id
 * @property int $hits
 */
final class ErrorEventCompany extends Model
{
    public $timestamps = false;

    protected $table = 'error_event_companies';

    protected $fillable = ['error_event_id', 'company_id', 'hits', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ErrorEvent, $this>
     */
    public function errorEvent(): BelongsTo
    {
        return $this->belongsTo(ErrorEvent::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
