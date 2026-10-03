<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Una respuesta de la DGII o del proveedor. SOLO SE INSERTA: modificar o borrar una respuesta
 * reescribiría la historia fiscal de un documento, y aquí se impide en el propio modelo.
 *
 * @property string $outcome
 * @property array<int, array<string, mixed>>|null $messages
 */
final class ElectronicInvoiceResponse extends Model implements HasCompany
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Las respuestas de la DGII no se modifican: solo se añaden.'));
        static::deleting(fn () => throw new LogicException('Las respuestas de la DGII no se borran.'));
    }

    protected function casts(): array
    {
        return ['messages' => 'array', 'sequence_used' => 'boolean'];
    }
}
