<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede los permisos del dealer a los roles que ya existen.
 *
 * Los permisos se reparten al CREAR la empresa (RoleProvisioner, sobre el evento CompanyCreated), así
 * que unos permisos nuevos no llegan solos a las empresas que ya estaban: sus roles se quedarían sin
 * ellos y las pantallas de vehículos les darían un 403 sin que nadie hubiera cambiado nada.
 *
 * Solo `owner` y `admin`, igual que en el aprovisionamiento: el cajero cobra en el mostrador y no
 * administra un patio de carros.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'vehicles.view',
        'vehicles.manage',
        'vehicle_deals.view',
        'vehicle_deals.manage',
        'vehicle_jobs.view',
        'vehicle_jobs.manage',
    ];

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

        /*
         * Sin esto el permiso queda concedido en la base de datos y NEGADO en la práctica.
         *
         * Spatie guarda el mapa de permisos en caché y lo escribe con Eloquent; una migración que
         * inserta con el Query Builder no lo invalida. El resultado es una pantalla que devuelve 403
         * con el permiso ya en la tabla: todo parece correcto menos lo que ve el usuario. Ya pasó al
         * probar la migración de las solicitudes de préstamo.
         */
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
