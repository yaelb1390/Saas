<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea el rol «Repartidor» y el permiso `delivery.own` en las empresas que ya existen.
 *
 * Los roles se reparten al crear la empresa (RoleProvisioner, sobre el evento CompanyCreated), así
 * que un rol nuevo no llega solo a las que ya estaban: no podrían asignárselo a nadie y la pantalla
 * de usuarios ofrecería un rol que no existe en su empresa.
 *
 * Los roles son POR EMPRESA (spatie con equipos): hay un «owner» por cada una, y hace falta un
 * «driver» por cada una también.
 */
return new class extends Migration
{
    private const PERMISO = 'delivery.own';

    /** Quién más lo recibe: en un colmado el dueño también reparte. */
    private const ROLES_EXISTENTES = ['owner', 'admin'];

    public function up(): void
    {
        $permisoId = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permisoId === null) {
            $permisoId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISO,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Un rol «driver» por empresa. Se recorren las empresas y no los roles existentes porque el
        // rol nuevo hay que CREARLO, no solo ampliarlo.
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $driverId = DB::table('roles')
                ->where('company_id', $companyId)->where('name', 'driver')->value('id');

            if ($driverId === null) {
                $driverId = DB::table('roles')->insertGetId([
                    'company_id' => $companyId,
                    'name' => 'driver',
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roles = DB::table('roles')
                ->where('company_id', $companyId)
                ->whereIn('name', self::ROLES_EXISTENTES)
                ->pluck('id')
                ->push($driverId);

            foreach ($roles as $roleId) {
                $yaLoTiene = DB::table('role_has_permissions')
                    ->where('permission_id', $permisoId)->where('role_id', $roleId)->exists();

                if (! $yaLoTiene) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permisoId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Sin esto el permiso queda concedido en la base de datos y NEGADO en la práctica: spatie
        // guarda el mapa en caché 24 h y una migración que inserta con el Query Builder no lo
        // invalida. Ya mordió una vez con las solicitudes de préstamo.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permisoId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }

        // Los usuarios que tuvieran el rol se quedan sin él: es lo correcto, el rol deja de existir.
        $driverIds = DB::table('roles')->where('name', 'driver')->pluck('id');

        if ($driverIds->isNotEmpty()) {
            DB::table('model_has_roles')->whereIn('role_id', $driverIds)->delete();
            DB::table('roles')->whereIn('id', $driverIds)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
