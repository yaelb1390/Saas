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
        'polar_product_id',
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

    /**
     * ¿Se puede contratar en línea?
     *
     * Solo si está enlazado con un producto de la pasarela de cobro. Un plan sin enlazar sigue
     * existiendo y se asigna a mano, pero no se le puede ofrecer un botón de pago: no habría forma
     * de saber qué activar cuando llegara el aviso de cobro.
     */
    public function isPurchasable(): bool
    {
        return $this->is_active && filled($this->polar_product_id);
    }

    /**
     * Busca el plan enlazado con un producto de Polar.
     *
     * Es la traducción que hará falta al recibir el aviso de pago: Polar dice qué producto se
     * compró, y esto dice qué plan activar.
     */
    public static function forPolarProduct(string $productId): ?self
    {
        return static::query()->where('polar_product_id', $productId)->first();
    }
}
