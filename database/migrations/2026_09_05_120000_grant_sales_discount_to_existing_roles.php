<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede «sales.discount» a los roles que ya existen.
 *
 * Los permisos se reparten al crear la empresa (RoleProvisioner, sobre el evento CompanyCreated), así
 * que uno nuevo no llega solo a las empresas que ya estaban: sus roles se quedarían sin él y, con el
 * tope activo, el dueño no podría rebajar más que su propio cajero.
 *
 * Lo reciben `owner` y `admin`, igual que en el aprovisionamiento. El cajero NO: puede rebajar, pero
 * hasta el tope que fije la empresa. Ese es justamente el punto de separarlo.
 */
return new class extends Migration
{
    private const PERMISO = 'sales.discount';

    private const ROLES = ['owner', 'admin'];

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

        // Los roles son por empresa (spatie con equipos), así que hay uno «owner» por cada una.
        $roles = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');

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

        /*
         * SIN ESTO EL PERMISO QUEDA CONCEDIDO EN LA BASE Y DENEGADO EN LA PRÁCTICA.
         *
         * Spatie guarda el mapa de permisos en caché; escribir las filas a mano no la invalida. El
         * síntoma es de los peores de diagnosticar: la fila está, el rol es el correcto, y el sistema
         * responde que no. Ya pasó una vez en este proyecto.
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permisoId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
