<?php

declare(strict_types=1);

namespace App\Modules\Printing\Models;

use App\Modules\Core\Tenancy\BelongsToCompany;
use App\Modules\Core\Tenancy\HasCompany;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Cómo se ve un tipo de documento al imprimirse: qué campos se muestran, el tamaño de la fuente, si
 * lleva QR... Todo el diseño vive en `layout` (ver TemplateService::layoutPorDefecto() para su forma
 * exacta) para que el editor visual y el renderizador lean el mismo contrato.
 */
final class PrintTemplate extends Model implements Auditable, HasCompany
{
    use AuditableTrait;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'document_type',
        'name',
        'paper_size',
        'layout',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
