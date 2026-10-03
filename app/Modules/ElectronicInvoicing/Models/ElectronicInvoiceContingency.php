<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Database\Eloquent\Model;

/**
 * Un periodo en que no se pudo completar el envío normal [IT §19].
 *
 * @property Environment $environment
 * @property string $kind
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
final class ElectronicInvoiceContingency extends Model implements HasCompany
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'regularized_at' => 'datetime',
        ];
    }
}
