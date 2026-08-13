<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Core\Mail\PasswordResetMail;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Company;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
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

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Enlace para crear una contraseña nueva.
     *
     * Se sustituye la notificación de Laravel por dos motivos:
     *
     * 1. UNA CUENTA DESACTIVADA NO RECIBE NADA. El login ya la rechaza (ver FortifyServiceProvider),
     *    pero el restablecimiento iba por otro camino y sí la dejaba fijar contraseña nueva: acababa
     *    con una clave que no le servía para entrar. Se corta aquí, en el envío, y NO en la
     *    respuesta de la pantalla: el controlador sigue contestando lo mismo a todo el mundo, así
     *    que nadie puede averiguar qué correos tienen cuenta ni en qué estado están.
     *
     * 2. La de Laravel llega en inglés y con otra plantilla. Esta usa el mismo armazón que el resto
     *    de correos del sistema.
     */
    public function sendPasswordResetNotification($token): void
    {
        if (! $this->is_active) {
            return;
        }

        Mail::to($this->email)->send(new PasswordResetMail(
            ownerName: (string) $this->name,
            resetUrl: route('password.reset', ['token' => $token, 'email' => $this->email]),
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
            supportWhatsapp: (string) config('platform.support_whatsapp'),
            supportEmail: (string) config('platform.support_email'),
        ));
    }
}
