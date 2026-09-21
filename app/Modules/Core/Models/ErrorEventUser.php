<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Qué usuarios sufrieron un error, y cuántas veces cada uno.
 *
 * Una fila por (error, usuario). Responde a «¿a cuántas PERSONAS afecta?», que no es lo mismo que cuántas
 * veces ocurrió: un solo usuario pulsando diez veces el mismo botón roto son diez ocurrencias y un afectado.
 *
 * `company_id` es la empresa del usuario cuando se conoce (nulo para el operador de la plataforma).
 *
 * NO lleva `BelongsToCompany`: la lee el operador de la plataforma.
 *
 * @property int $error_event_id
 * @property int $user_id
 * @property int|null $company_id
 * @property int $hits
 */
final class ErrorEventUser extends Model
{
    public $timestamps = false;

    protected $table = 'error_event_users';

    protected $fillable = ['error_event_id', 'user_id', 'company_id', 'hits', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ErrorEvent, $this>
     */
    public function errorEvent(): BelongsTo
    {
        return $this->belongsTo(ErrorEvent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
