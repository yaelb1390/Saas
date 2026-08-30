<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una foto de la galería.
 *
 * La principal se marca aquí y se COPIA a `vehicles.photo_path`. Es un dato repetido a propósito: la
 * lista del patio pinta cientos de miniaturas y resolver cuál es la principal con una consulta por
 * fila sería el N+1 más caro de la pantalla. Quien cambie la principal tiene que actualizar las dos
 * cosas, y de eso se encarga un único sitio: `VehiclePhotoStore`.
 */
class VehiclePhoto extends Model implements HasCompany
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'path',
        'position',
        'is_primary',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La dirección para verla, con versión.
     *
     * El `?v=` evita que al reemplazar una foto el navegador siga enseñando la vieja durante días.
     */
    public function url(): string
    {
        return route('panel.vehicles.photos.show', [$this->vehicle_id, $this->id])
            .'?v='.($this->updated_at?->timestamp ?? 0);
    }
}
