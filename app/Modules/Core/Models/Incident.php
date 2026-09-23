<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Esto se rompió, y esto es lo que se sabe de él»: un problema lo bastante grave como para que
 * alguien lo mire, con su código, su severidad y su historia.
 *
 * NO lleva `BelongsToCompany`, igual que `ErrorEvent` y `SystemEvent`: lo lee el operador de la
 * plataforma para ver todos los incidentes a la vez. El desglose por empresa vive en `companies()`.
 *
 * @property int $id
 * @property string $code
 * @property int $year
 * @property int $seq
 * @property string $title
 * @property string|null $service
 * @property string $severity
 * @property string $status
 * @property int $occurrences
 * @property int $companies_count
 * @property string|null $dedupe_key
 * @property string $source
 */
final class Incident extends Model
{
    public const OPEN = 'open';

    public const INVESTIGATING = 'investigating';

    public const RESOLVED = 'resolved';

    public const IGNORED = 'ignored';

    /** Los dos estados que cuentan como «sigue pidiendo atención». */
    public const ACTIVOS = [self::OPEN, self::INVESTIGATING];

    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    public const SEVERIDADES = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    public const FUENTE_AUTO = 'auto';

    public const FUENTE_MANUAL = 'manual';

    protected $fillable = [
        'code', 'year', 'seq', 'title', 'service', 'severity', 'status',
        'started_at', 'last_detected_at', 'resolved_at', 'occurrences', 'companies_count',
        'description', 'cause', 'resolution', 'dedupe_key', 'source', 'created_by', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'seq' => 'integer',
            'occurrences' => 'integer',
            'companies_count' => 'integer',
            'started_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<IncidentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(IncidentLink::class);
    }

    /**
     * @return HasMany<IncidentCompany, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(IncidentCompany::class);
    }

    /**
     * Los que siguen pidiendo atención: ni resueltos ni ignorados.
     *
     * @param  Builder<Incident>  $consulta
     * @return Builder<Incident>
     */
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->whereIn('status', self::ACTIVOS);
    }

    public function estaActivo(): bool
    {
        return in_array($this->status, self::ACTIVOS, true);
    }

    /** Cuánto duró abierto, o cuánto lleva si sigue activo. Null solo si faltan las fechas. */
    public function duracion(): ?\Carbon\CarbonInterval
    {
        if ($this->started_at === null) {
            return null;
        }

        $hasta = $this->resolved_at ?? now();

        return $this->started_at->diffAsCarbonInterval($hasta);
    }
}
