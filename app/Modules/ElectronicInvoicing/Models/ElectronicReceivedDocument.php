<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use App\Modules\ElectronicInvoicing\Domain\Environment;
use Illuminate\Database\Eloquent\Model;

/**
 * Un e-CF que otro contribuyente envió a la empresa (como receptora), con el acuse de recibo que se
 * le devolvió y, si la empresa la emite, su aprobación comercial.
 *
 * @property Environment $environment
 * @property int $receipt_status 0 recibido · 1 no recibido
 */
final class ElectronicReceivedDocument extends Model implements HasCompany
{
    use BelongsToCompany;

    public const RECIBIDO = 0;

    public const NO_RECIBIDO = 1;

    /** [arecf.xsd CodigoMotivoNoRecibidoType]. */
    public const MOTIVOS = [
        1 => 'Error de especificación',
        2 => 'Error de firma digital',
        3 => 'Envío duplicado',
        4 => 'RNC comprador no corresponde',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'receipt_status' => 'integer',
            'receipt_reason' => 'integer',
            'approval_status' => 'integer',
            'issue_date' => 'date',
            'total' => 'decimal:2',
            'itbis_total' => 'decimal:2',
            'approval_sent_at' => 'datetime',
        ];
    }
}
