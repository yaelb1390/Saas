<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope que aísla automáticamente cualquier modelo con company_id a la empresa activa.
 *
 * Si no hay empresa activa (CurrentCompany::has() === false) no se aplica ningún filtro, lo
 * que permite operar en consola, migraciones y flujos de super administrador. Nunca se debe
 * confiar en un company_id proveniente del cliente: el filtro siempre nace del contexto.
 */
final class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentCompany::class);

        if ($current->has() && $model instanceof HasCompany) {
            $builder->where(
                $model->getTable().'.'.$model->getCompanyIdColumn(),
                $current->id()
            );
        }
    }
}
