<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
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
     * @param  array{name: string, email: string, password: string, employee_id?: int|string|null}  $data
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
            $this->linkEmployee($user, $data['employee_id'] ?? null);

            return $user;
        });
    }

    /**
     * @param  array{name: string, email: string, role: string, password?: ?string, is_active?: bool, employee_id?: int|string|null}  $data
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

            // `array_key_exists` y no `??`: al editar sin tocar el campo el formulario manda el valor
            // vacío y hay que DESVINCULAR. Con `??` un empleado no se podría soltar nunca.
            if (array_key_exists('employee_id', $data)) {
                $this->linkEmployee($user, $data['employee_id']);
            }

            return $user->refresh();
        });
    }

    /**
     * Dice de quién es esta cuenta.
     *
     * De aquí sale qué entregas ve un repartidor en su móvil, así que se hace en dos pasos y en este
     * orden: primero se suelta al empleado que tuviera esta cuenta, después se la cuelga al elegido.
     * Al revés quedarían dos empleados apuntando al mismo usuario y la consulta del portal —que busca
     * por `user_id`— tendría que elegir entre dos, con el dinero de uno en la pantalla del otro.
     */
    private function linkEmployee(User $user, int|string|null $employeeId): void
    {
        Employee::query()->where('user_id', $user->id)->update(['user_id' => null]);

        if ($employeeId === null || $employeeId === '') {
            return;
        }

        Employee::query()->whereKey($employeeId)->update(['user_id' => $user->id]);
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
