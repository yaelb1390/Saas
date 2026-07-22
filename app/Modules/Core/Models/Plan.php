<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Enums\BillingCycle;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Plan de suscripción. Vive en la plataforma (no pertenece a ninguna empresa), por eso no lleva
 * el trait de tenant. modules NULL = el plan incluye todos los módulos.
 *
 * @property BillingCycle $billing_cycle
 * @property array<int, string>|null $modules
 */
class Plan extends Model implements Auditable
{
    use AuditableTrait;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_cycle',
        'trial_days',
        'modules',
        'max_users',
        'max_branches',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_cycle' => BillingCycle::class,
            'trial_days' => 'integer',
            'modules' => 'array',
            'max_users' => 'integer',
            'max_branches' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Módulos incluidos en el plan (todos si es null).
     *
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return $this->modules ?? ModuleRegistry::keys();
    }

    public function includesModule(string $key): bool
    {
        return $this->modules === null || in_array($key, $this->modules, true);
    }
}
