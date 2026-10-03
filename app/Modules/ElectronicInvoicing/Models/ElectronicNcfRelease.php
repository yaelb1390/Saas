<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\EcfType;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un e-NCF devuelto al uso porque la DGII lo rechazó con `secuenciaUtilizada = false` [DT p.24].
 *
 * @property int $number
 * @property \Illuminate\Support\Carbon|null $reused_at
 */
final class ElectronicNcfRelease extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'electronic_ncf_sequence_id', 'environment', 'ecf_type', 'number', 'reason', 'reused_at',
    ];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'ecf_type' => EcfType::class,
            'number' => 'integer',
            'reused_at' => 'datetime',
        ];
    }
}
