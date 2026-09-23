<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De dónde salió un incidente: un grupo de errores, un suceso del sistema o (Fase 3) una
 * comprobación de salud.
 *
 * @property int $incident_id
 * @property string $source_type
 * @property int $source_id
 */
final class IncidentLink extends Model
{
    public const ERROR_EVENT = 'error_event';

    public const SYSTEM_EVENT = 'system_event';

    public const HEALTH_CHECK = 'health_check';

    protected $fillable = ['incident_id', 'source_type', 'source_id'];

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
