<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Models\Audit as BaseAudit;

/**
 * El registro de auditoría, con la empresa a la que pertenece.
 *
 * La tabla que trae la librería NO guarda de qué empresa es cada fila, y sin eso no se puede
 * responder a «¿qué pasó en la empresa X?» salvo uniendo por los treinta tipos distintos de modelo
 * auditado. Se añade aquí, al crear, tomándola del inquilino activo.
 *
 * NO usa `BelongsToCompany` a propósito. Ese trait añade el `CompanyScope`, y esta tabla la lee el
 * operador de la plataforma para ver TODAS las empresas a la vez: con el ámbito puesto, la pantalla
 * de monitoreo enseñaría solo las de la empresa que él tenga abierta, sin dar error.
 *
 * @property int|null $company_id
 */
final class Audit extends BaseAudit
{
    protected $table = 'audits';

    public static function boot(): void
    {
        parent::boot();

        // Mismo gancho que `BelongsToCompany::bootBelongsToCompany()`, sin el ámbito: la fila se
        // marca con la empresa que estuviera activa cuando ocurrió.
        self::creating(function (self $audit): void {
            if ($audit->company_id === null) {
                $actual = app(CurrentCompany::class);

                if ($actual->has()) {
                    $audit->company_id = $actual->id();
                }
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
