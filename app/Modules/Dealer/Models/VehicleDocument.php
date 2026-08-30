<?php

declare(strict_types=1);

namespace App\Modules\Dealer\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\Dealer\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un papel de la unidad: matrícula, factura, seguro, importación, contrato.
 *
 * Auditable a propósito: aquí dentro va la cédula del comprador, el precio de la factura y las
 * condiciones del contrato. Si alguien sustituye o borra uno, tiene que quedar constancia de quién
 * fue.
 *
 * @property DocumentType $type
 */
class VehicleDocument extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'vehicle_id',
        'type',
        'path',
        'original_name',
        'mime',
        'size',
        'notes',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size' => 'integer',
        ];
    }

    /**
     * La ruta del fichero NO se audita.
     *
     * Guardarla en el rastro dejaría la dirección interna del documento repetida en una tabla que
     * lee el operador de la plataforma. El rastro tiene que decir QUE se cambió el documento, no
     * dónde está guardado.
     *
     * @var array<int, string>
     */
    protected $auditExclude = ['path'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** El tamaño en algo que una persona pueda leer. */
    public function tamanoLegible(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
