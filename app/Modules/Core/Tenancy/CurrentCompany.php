<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

use App\Modules\Core\Models\Company;

/**
 * Contexto de la empresa (tenant) activa durante el ciclo de vida de la petición.
 *
 * Se registra como singleton en el contenedor. El middleware SetCurrentCompany lo
 * inicializa a partir del usuario autenticado; el CompanyScope y el trait BelongsToCompany
 * lo consultan para aislar automáticamente las consultas y asignar company_id al crear.
 *
 * Un valor nulo significa "sin tenant activo" (consola, super admin o antes de autenticar),
 * en cuyo caso NO se aplica el filtro por company_id.
 */
final class CurrentCompany
{
    private ?int $companyId = null;

    /** Modelo de la empresa activa, cargado como mucho una vez por petición. */
    private ?Company $company = null;

    private bool $resolved = false;

    public function set(?int $companyId): void
    {
        $this->companyId = $companyId;
        $this->flushModel();
    }

    public function id(): ?int
    {
        return $this->companyId;
    }

    public function has(): bool
    {
        return $this->companyId !== null;
    }

    /**
     * La empresa activa con su suscripción y plan ya cargados, resuelta UNA sola vez por petición.
     *
     * Antes cada middleware, el layout, el dashboard y varios controladores hacían su propio
     * `Company::find()`: la misma fila se leía cinco o más veces por página. Al centralizarlo aquí,
     * todos comparten la misma instancia (y, por tanto, su relación ya cargada).
     */
    public function model(): ?Company
    {
        if ($this->companyId === null) {
            return null;
        }

        if (! $this->resolved) {
            $this->company = Company::query()->with('subscription.plan')->find($this->companyId);
            $this->resolved = true;
        }

        return $this->company;
    }

    public function forget(): void
    {
        $this->companyId = null;
        $this->flushModel();
    }

    /**
     * Olvida el modelo memorizado (al cambiar de empresa o tras modificarla).
     */
    public function flushModel(): void
    {
        $this->company = null;
        $this->resolved = false;
    }
}
