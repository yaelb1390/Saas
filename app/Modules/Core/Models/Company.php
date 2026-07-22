<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Empresa (tenant). Es la raíz del aislamiento multiempresa; por eso NO usa el trait
 * BelongsToCompany: no pertenece a otra empresa, las demás entidades pertenecen a ella.
 */
class Company extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'tax_id',
        'email',
        'phone',
        'address',
        'currency',
        'timezone',
        'logo_path',
        'is_active',
        'settings',
        'modules',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'modules' => 'array',
        ];
    }

    /**
     * ¿Tiene la empresa acceso a este módulo ahora mismo?
     *
     * Prioridad:
     *   1. Con suscripción: los módulos salen del plan, y solo mientras esté al día. Una
     *      suscripción vencida/suspendida no da acceso a ningún módulo.
     *   2. Sin suscripción (empresas previas a la facturación por planes): se usa la columna
     *      `modules` (NULL = todos), conservando el comportamiento anterior.
     */
    public function hasModule(string $key): bool
    {
        $subscription = $this->loadedSubscription();

        if ($subscription !== null) {
            if (! $subscription->isUsable() || $subscription->plan === null) {
                return false;
            }

            // La empresa puede tener un ajuste manual (override) sobre los módulos del plan;
            // si no lo tiene (NULL), hereda exactamente los del plan.
            return in_array($key, $this->modules ?? $subscription->plan->moduleKeys(), true);
        }

        return $this->modules === null || in_array($key, $this->modules, true);
    }

    /**
     * Claves de los módulos activos de la empresa.
     *
     * @return array<int, string>
     */
    public function activeModules(): array
    {
        $subscription = $this->loadedSubscription();

        if ($subscription !== null) {
            if (! $subscription->isUsable() || $subscription->plan === null) {
                return [];
            }

            // Override manual de la empresa (si existe) o los módulos del plan.
            return $this->modules ?? $subscription->plan->moduleKeys();
        }

        return $this->modules ?? ModuleRegistry::keys();
    }

    /**
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * Suscripción con su plan, cargada UNA sola vez por instancia.
     *
     * Antes hacía `->first()` sin guardar el resultado: como hasModule() se llama una vez por cada
     * elemento del menú y por cada tarjeta del panel, la MISMA consulta se repetía decenas de veces
     * (79 consultas en el dashboard). Al cargar la relación, las siguientes llamadas la reutilizan.
     */
    private function loadedSubscription(): ?Subscription
    {
        if (! $this->relationLoaded('subscription')) {
            $this->load(['subscription.plan']);
        }

        return $this->getRelation('subscription');
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
