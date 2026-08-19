<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use OwenIt\Auditing\Models\Audit as BaseAudit;
use Throwable;

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

    /**
     * Si la columna existe ya. Se comprueba UNA vez por proceso.
     *
     * No es paranoia. Aquí las migraciones se aplican a mano y el despliegue no las corre, así que
     * el código llega SIEMPRE antes que el cambio en la base. Sin esta comprobación, ese hueco no
     * significaba «la pantalla de monitoreo todavía no se ve»: significaba que cada venta, cada
     * producto y cada cliente que alguien guardara moría con un error de SQL, porque los treinta
     * modelos están auditados y el INSERT del rastro llevaría una columna que no existe.
     *
     * Que falle escribir el rastro NO puede tumbar lo que estaba pasando. En cuanto la migración se
     * aplica, esto pasa solo a `true` en el siguiente proceso y no hay que tocar nada.
     */
    private static ?bool $tieneEmpresa = null;

    public static function boot(): void
    {
        parent::boot();

        // Mismo gancho que `BelongsToCompany::bootBelongsToCompany()`, sin el ámbito: la fila se
        // marca con la empresa que estuviera activa cuando ocurrió.
        self::creating(function (self $audit): void {
            if (! self::columnaDeEmpresa()) {
                return;
            }

            if ($audit->company_id === null) {
                $actual = app(CurrentCompany::class);

                if ($actual->has()) {
                    $audit->company_id = $actual->id();
                }
            }
        });
    }

    private static function columnaDeEmpresa(): bool
    {
        if (self::$tieneEmpresa === null) {
            // Si ni siquiera se puede preguntar, se decide que no: perder la empresa de una fila del
            // rastro es recuperable —se rellena hacia atrás—, y tumbar la venta que lo produjo no.
            try {
                self::$tieneEmpresa = Schema::hasColumn('audits', 'company_id');
            } catch (Throwable) {
                self::$tieneEmpresa = false;
            }
        }

        return self::$tieneEmpresa;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
