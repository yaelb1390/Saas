<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Bitácora de facturación electrónica: cada acción y cambio de estado, con usuario e IP. Igual que
 * las respuestas, solo se inserta.
 */
final class ElectronicInvoiceAuditLog extends Model implements HasCompany
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora de e-CF no se modifica.'));
        static::deleting(fn () => throw new LogicException('La bitácora de e-CF no se borra.'));
    }

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
