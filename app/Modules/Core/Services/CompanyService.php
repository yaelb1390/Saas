<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Events\CompanyCreated;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Repositories\Contracts\CompanyRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lógica de negocio de empresas. Es el único lugar que orquesta la creación de un tenant:
 * genera el slug, persiste la empresa junto a su sucursal y almacén por defecto dentro de una
 * transacción, y emite el evento de dominio para automatizaciones.
 */
final class CompanyService
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companies,
    ) {}

    public function create(CreateCompanyData $data): Company
    {
        $company = DB::transaction(function () use ($data): Company {
            $company = $this->companies->create([
                'name' => $data->name,
                'slug' => $this->uniqueSlug($data->name),
                'legal_name' => $data->legalName,
                'tax_id' => $data->taxId,
                'email' => $data->email,
                'phone' => $data->phone,
                'address' => $data->address,
                'currency' => $data->currency,
                'timezone' => $data->timezone,
                'is_active' => true,
            ]);

            // Aprovisionamiento mínimo: sucursal principal + almacén por defecto.
            $branch = $company->branches()->create([
                'name' => 'Sucursal Principal',
                'code' => 'MAIN',
                'is_main' => true,
                'is_active' => true,
            ]);

            $company->warehouses()->create([
                'branch_id' => $branch->id,
                'name' => 'Almacén Principal',
                'code' => 'MAIN',
                'is_default' => true,
                'is_active' => true,
            ]);

            return $company;
        });

        CompanyCreated::dispatch($company);

        return $company;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while ($this->companies->findBySlug($slug) !== null) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
