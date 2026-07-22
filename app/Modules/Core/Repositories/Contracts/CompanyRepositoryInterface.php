<?php

declare(strict_types=1);

namespace App\Modules\Core\Repositories\Contracts;

use App\Modules\Core\Models\Company;
use Illuminate\Support\Collection;

/**
 * Contrato del repositorio de empresas. La capa de servicio depende de esta abstracción,
 * no de Eloquent, para poder sustituir la persistencia y facilitar el testeo.
 */
interface CompanyRepositoryInterface
{
    public function find(int $id): ?Company;

    public function findBySlug(string $slug): ?Company;

    /**
     * @return Collection<int, Company>
     */
    public function all(): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Company;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Company $company, array $attributes): Company;

    public function delete(Company $company): void;
}
