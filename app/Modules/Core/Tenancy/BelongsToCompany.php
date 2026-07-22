<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

use App\Modules\Core\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait para modelos que pertenecen a una empresa (tenant).
 *
 * - Registra el CompanyScope para aislar todas las consultas por la empresa activa.
 * - Al crear un registro, asigna automáticamente company_id desde el contexto si no viene dado.
 * - Expone la relación company().
 *
 * Cualquier modelo de negocio (productos, ventas, clientes, etc.) debe usar este trait y,
 * para tipado estático correcto, declarar `implements HasCompany`.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model): void {
            if ($model->getAttribute($model->getCompanyIdColumn()) === null) {
                $current = app(CurrentCompany::class);

                if ($current->has()) {
                    $model->setAttribute($model->getCompanyIdColumn(), $current->id());
                }
            }
        });
    }

    public function getCompanyIdColumn(): string
    {
        return 'company_id';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, $this->getCompanyIdColumn());
    }

    /**
     * Consulta sin el aislamiento por empresa. Uso restringido a super administradores
     * o procesos de plataforma; nunca en flujos de usuario final.
     */
    public static function withoutCompanyScope(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }
}
