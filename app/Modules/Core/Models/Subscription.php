<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Suscripción vigente de una empresa. El acceso a los módulos se deriva del plan siempre que la
 * suscripción esté «usable» (activa o en prueba y dentro del período vigente).
 *
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_end
 */
class Subscription extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * ¿Da acceso al sistema ahora mismo?
     *
     * - En prueba: mientras no venza el trial.
     * - Activa: mientras no venza el período pagado.
     * Vencida, suspendida o cancelada: no.
     */
    public function isUsable(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Trialing => $this->trial_ends_at === null || $this->trial_ends_at->isFuture(),
            SubscriptionStatus::Active => $this->current_period_end === null || $this->current_period_end->isFuture(),
            default => false,
        };
    }

    /**
     * Fecha hasta la que da acceso (fin de prueba o fin del período pagado).
     */
    public function renewsAt(): ?Carbon
    {
        return $this->status === SubscriptionStatus::Trialing
            ? $this->trial_ends_at
            : $this->current_period_end;
    }

    public function isTrialing(): bool
    {
        return $this->status === SubscriptionStatus::Trialing;
    }

    /**
     * Días de calendario desde hoy hasta la renovación (fin de prueba o del período). 0 = vence hoy;
     * negativo = ya venció; null si no hay fecha.
     *
     * Se compara de medianoche a medianoche (ignorando la hora) a propósito: así la cuenta baja
     * exactamente al cambiar de día y coincide con la fecha mostrada. Si se usara la hora exacta, el
     * número bajaría a la hora en que se creó la prueba (no a medianoche) y parecería «pegado» la
     * mañana siguiente.
     */
    public function daysUntilRenewal(): ?int
    {
        $renews = $this->renewsAt();

        if ($renews === null) {
            return null;
        }

        $today = Carbon::now()->startOfDay();

        return (int) round($today->diffInDays($renews->copy()->startOfDay(), false));
    }
}
