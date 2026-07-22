<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\DTOs\CreateCompanyData;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Alta completa de una empresa desde el panel de la plataforma: crea el tenant (empresa +
 * sucursal + almacén + roles, vía CompanyService), fija su plan de módulos y da de alta a su
 * usuario propietario. Es el punto único de onboarding del SaaS.
 */
final class CompanyOnboardingService
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly CompanyUserService $users,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $owner
     * @param  array<int, string>|null  $modules  null = plan completo
     */
    public function register(CreateCompanyData $data, array $owner, ?array $modules = null): Company
    {
        return DB::transaction(function () use ($data, $owner, $modules): Company {
            // CompanyService emite CompanyCreated, cuyo listener aprovisiona los roles de la
            // empresa de forma síncrona: por eso ya existen cuando damos de alta al propietario.
            $company = $this->companies->create($data);

            $sanitized = $modules === null ? null : ModuleRegistry::sanitize($modules);

            // Si el plan cubre todos los módulos, se guarda null (plan completo).
            if ($sanitized !== null && count($sanitized) !== count(ModuleRegistry::keys())) {
                $company->update(['modules' => $sanitized]);
            }

            $this->users->create((int) $company->id, $owner, 'owner');

            return $company->refresh();
        });
    }

    /**
     * Usuarios existentes (evita duplicar el correo, que es la credencial de acceso global).
     */
    public function emailTaken(string $email): bool
    {
        return User::where('email', $email)->exists();
    }
}
