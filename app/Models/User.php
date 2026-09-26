<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Company;
use App\Modules\HR\Models\Employee;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements Auditable
{
    use AuditableTrait;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'email',
        'google_id',
        'password',
        'is_super_admin',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Atributos excluidos de la auditoría (secretos y ruido).
     *
     * @var array<int, string>
     */
    protected $auditExclude = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * La ficha de empleado de esta cuenta, si la tiene.
     *
     * El vínculo se guarda del otro lado (`employees.user_id`), así que hasta ahora cada sitio que lo
     * necesitaba escribía su propia consulta: el portal del empleado, el del repartidor y la pantalla
     * de usuarios. Tres consultas iguales que podían dejar de serlo.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** ¿Esta cuenta es de alguien de la plantilla? */
    public function esEmpleado(): bool
    {
        return $this->employee()->exists();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }
}
