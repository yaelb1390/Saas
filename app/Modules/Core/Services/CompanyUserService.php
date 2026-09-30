<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Models\User;
use App\Modules\Core\Exceptions\CoreException;
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
        $this->assertDentroDelTope($companyId);

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
            $this->fichaSiEsRepartidor($user, $role);

            return $user;
        });
    }

    /**
     * El tope de usuarios del plan. `null` significa sin plan o plan sin tope, y no se comprueba
     * nada: es el mismo criterio «null = sin límite» que ya usa `CompanyHealthService`.
     *
     * Cuenta TODOS los usuarios de la empresa —activos e inactivos, salvo el super admin—, igual
     * que ya lo hace el panel de salud: si contara solo los activos, desactivar y reactivar gente
     * sería una forma de esquivar el tope del plan.
     */
    private function assertDentroDelTope(int $companyId): void
    {
        $plan = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.company_id', $companyId)
            ->select('plans.name', 'plans.max_users')
            ->first();

        if ($plan === null || $plan->max_users === null) {
            return;
        }

        $actuales = User::query()
            ->where('company_id', $companyId)
            ->where('is_super_admin', false)
            ->count();

        if ($actuales >= $plan->max_users) {
            throw CoreException::maxUsersReached((int) $plan->max_users, (string) $plan->name);
        }
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

            $this->fichaSiEsRepartidor($user, $data['role']);

            return $user->refresh();
        });
    }

    /**
     * Un repartidor SIEMPRE tiene ficha de empleado. Si no se eligió una, se le crea.
     *
     * No es una comodidad: es que un usuario con rol «Repartidor» y sin ficha NO SIRVE PARA NADA. No
     * aparece en la lista de repartidores de Entregas —que se saca de los empleados, no de los
     * usuarios— y su portal sale vacío. Se podía crear así, y no había nada que lo impidiera: la
     * pantalla lo avisaba en amarillo y ahí terminaba.
     *
     * Solo para este rol. Un dueño o un administrador no tienen por qué estar en la plantilla.
     */
    private function fichaSiEsRepartidor(User $user, string $role): void
    {
        if ($role !== 'driver' || $user->employee()->exists()) {
            return;
        }

        Employee::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'position' => 'Repartidor',
            'hired_at' => now()->toDateString(),
            'is_active' => true,
        ]);
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
