<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede «loan_applications.view» y «loan_applications.manage» a los roles que ya existen.
 *
 * Los permisos se reparten al crear la empresa (RoleProvisioner, sobre el evento CompanyCreated), así
 * que unos permisos nuevos no llegan solos a las empresas que ya estaban: sus roles se quedarían sin
 * ellos y la pantalla de solicitudes les daría un 403 sin que nadie hubiera cambiado nada.
 *
 * Solo los reciben `owner` y `admin`, igual que en el aprovisionamiento. El cajero no: una solicitud
 * de préstamo lleva ingresos, cédula del garante y el historial de deuda del cliente, que no es
 * información de mostrador.
 */
return new class extends Migration
{
    private const PERMISOS = ['loan_applications.view', 'loan_applications.manage'];

    private const ROLES = ['owner', 'admin'];

    public function up(): void
    {
        // Los roles son por empresa (spatie con equipos), así que hay un «owner» por cada una.
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

        // Sin esto el permiso queda concedido en la base de datos y NEGADO en la práctica.
        //
        // Spatie guarda el mapa de permisos en caché (24 h por defecto) y lo escribe con Eloquent;
        // una migración que inserta con el Query Builder no lo invalida. El resultado es una
        // pantalla que devuelve 403 con el permiso ya en la tabla, que es de los fallos más difíciles
        // de diagnosticar: todo parece correcto menos lo que ve el usuario. Pasó aquí mismo al
        // probar esta migración.
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
