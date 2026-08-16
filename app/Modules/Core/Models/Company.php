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
        'social_api_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'modules' => 'array',
            // Credencial de Zernio: cifrada en reposo. Quien la tenga puede publicar en el Instagram
            // del cliente, así que no puede quedar legible para nadie que abra la tabla.
            'social_api_key' => 'encrypted',
        ];
    }

    /** ¿Tiene esta empresa conectadas sus redes sociales? */
    public function publicaEnRedes(): bool
    {
        return filled($this->social_api_key);
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

    /**
     * El propietario de la cuenta: el primer usuario activo (se crea primero en el onboarding). Es el
     * destinatario de los correos de cuenta/suscripción.
     */
    public function ownerUser(): ?User
    {
        return $this->users()->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Funciones que la empresa enciende o apaga por su cuenta.
     *
     * No son módulos: un módulo es lo que se CONTRATA y decide el plan; esto es lo que el propio
     * cliente elige usar de lo que ya tiene. Una heladería y una ferretería pueden tener las dos el
     * terminal táctil, y solo a una le sirven los tamaños y sabores.
     *
     * Viven en `settings` y no en columnas propias porque son interruptores que van y vienen; una
     * migración por cada uno sería una columna nueva cada vez que alguien pide ocultar algo.
     *
     * @var array<string, string>
     */
    public const FEATURES = [
        'option_groups' => 'Tamaños y sabores',
    ];

    /**
     * ¿Tiene encendida esta función?
     *
     * Apagada por defecto: el menú de un colmado no tiene por qué llevar una entrada de sabores de
     * helado. Las empresas que YA usaban la función la conservan porque una migración se la dejó
     * encendida al implantar esto.
     */
    public function usesFeature(string $key): bool
    {
        return (bool) data_get($this->settings, "features.{$key}", false);
    }

    public function hasLogo(): bool
    {
        return $this->logo_path !== null && $this->logo_path !== '';
    }

    /**
     * URL del logo para el recibo que se mira en pantalla.
     *
     * Lleva la marca de tiempo detrás para que, al cambiar el logo, el navegador no siga enseñando el
     * anterior: la dirección es la misma y sin esto la caché la daría por buena durante días.
     */
    public function logoUrl(): ?string
    {
        if (! $this->hasLogo()) {
            return null;
        }

        return route('panel.company.logo').'?v='.($this->updated_at?->timestamp ?? 0);
    }

    /** El nombre que debe salir en un documento: el legal si lo hay, y si no el comercial. */
    public function nombreParaDocumentos(): string
    {
        $legal = trim((string) $this->legal_name);

        return $legal !== '' ? $legal : (string) $this->name;
    }
}
