<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede los permisos del alquiler a los roles que ya existen.
 *
 * Mismo motivo que la migración equivalente del Dealer: los permisos se reparten al CREAR la empresa
 * (RoleProvisioner, sobre CompanyCreated), así que uno nuevo no llega solo a las empresas que ya
 * estaban — sus roles se quedarían sin él y las pantallas de alquiler les darían un 403 sin que nadie
 * hubiera tocado nada.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'vehicle_rentals.view',
        'vehicle_rentals.manage',
    ];

    private const ROLES = ['owner', 'admin'];

    public function up(): void
    {
        $roles = DB::table('roles')->whereIn('name', self::ROLES)->pluck('id');

        foreach (self::PERMISOS as $permiso) {
            $permisoId = DB::table('permissions')->where('name', $permiso)->value('id');

            if ($permisoId === null) {
                $permisoId = DB::table('permissions')->insertGetId([
                    'name' => $permiso,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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

        // Sin esto el permiso queda concedido en la base y negado en la práctica: Spatie cachea el
        // mapa de permisos y una migración que inserta con el Query Builder no lo invalida.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::PERMISOS as $permiso) {
            $permisoId = DB::table('permissions')->where('name', $permiso)->value('id');

            if ($permisoId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
                DB::table('permissions')->where('id', $permisoId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
