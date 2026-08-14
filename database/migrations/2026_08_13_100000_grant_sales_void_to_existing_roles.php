<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Concede «sales.void» a los roles que ya existen.
 *
 * Los permisos se reparten al crear la empresa (RoleProvisioner, sobre el evento CompanyCreated), así
 * que un permiso nuevo no llega solo a las empresas que ya estaban: sus roles se quedarían sin él y
 * la pantalla de anular ventas les daría un 403 sin que nadie hubiera cambiado nada.
 *
 * Solo lo reciben `owner` y `admin`, igual que en el aprovisionamiento. El cajero no: anular una
 * venta devuelve el stock y saca el cobro de la caja, y quien cobra no deshace cobros.
 */
return new class extends Migration
{
    private const PERMISO = 'sales.void';

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
    }

    public function down(): void
    {
        $permisoId = DB::table('permissions')->where('name', self::PERMISO)->value('id');

        if ($permisoId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permisoId)->delete();
            DB::table('permissions')->where('id', $permisoId)->delete();
        }
    }
};
