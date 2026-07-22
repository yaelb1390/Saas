<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Alta y mantenimiento de los usuarios de una empresa, incluida la asignación de rol.
 *
 * Los roles de spatie están particionados por empresa (teams). Asignar o cambiar un rol exige
 * fijar antes el equipo correcto; esa mecánica se concentra aquí para que ninguna otra capa tenga
 * que conocerla.
 */
final class CompanyUserService
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function create(int $companyId, array $data, string $role): User
    {
        return DB::transaction(function () use ($companyId, $data, $role): User {
            $user = User::create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
            ]);

            $this->assignRole($user, $role);

            return $user;
        });
    }

    /**
     * @param  array{name: string, email: string, role: string, password?: ?string, is_active?: bool}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'] ?? $user->is_active,
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            $this->assignRole($user, $data['role']);

            return $user->refresh();
        });
    }

    public function setActive(User $user, bool $active): User
    {
        $user->update(['is_active' => $active]);

        return $user;
    }

    /**
     * Reemplaza los roles del usuario dentro del equipo (empresa) que le corresponde.
     */
    private function assignRole(User $user, string $role): void
    {
        $this->registrar->setPermissionsTeamId((int) $user->company_id);
        $user->syncRoles([$role]);
        $this->registrar->forgetCachedPermissions();
    }
}
