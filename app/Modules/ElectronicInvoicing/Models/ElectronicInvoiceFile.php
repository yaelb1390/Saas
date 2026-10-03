<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Un archivo de un e-CF guardado en el disco privado `fiscal_documents`, con su huella para poder
 * demostrar que no ha cambiado.
 *
 * @property string $kind
 * @property string $path
 * @property string $sha256
 */
final class ElectronicInvoiceFile extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $hidden = ['path'];
}
