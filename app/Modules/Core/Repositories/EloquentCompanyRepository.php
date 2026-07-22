<?php

declare(strict_types=1);

namespace App\Modules\Core\Repositories;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Support\Collection;

final class EloquentCompanyRepository implements CompanyRepositoryInterface
{
    public function find(int $id): ?Company
    {
        return Company::find($id);
    }

    public function findBySlug(string $slug): ?Company
    {
        return Company::where('slug', $slug)->first();
    }

    public function all(): Collection
    {
        return Company::orderBy('name')->get();
    }

    public function create(array $attributes): Company
    {
        return Company::create($attributes);
    }

    public function update(Company $company, array $attributes): Company
    {
        $company->update($attributes);

        return $company->refresh();
    }

    public function delete(Company $company): void
    {
        $company->delete();
    }
}
