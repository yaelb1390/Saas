<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuántas veces sufrió UNA empresa un incidente. Igual que `ErrorEventCompany`, para incidentes.
 *
 * NO lleva `BelongsToCompany`: la lee el operador de la plataforma, que necesita ver todas las
 * empresas a la vez.
 *
 * @property int $incident_id
 * @property int $company_id
 * @property int $hits
 */
final class IncidentCompany extends Model
{
    public $timestamps = false;

    protected $table = 'incident_companies';

    protected $fillable = ['incident_id', 'company_id', 'hits', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
