<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede los permisos de cotizaciones a los roles que ya existen.
 *
 * Los permisos se reparten al crear la empresa, así que unos nuevos no llegan solos a las que ya
 * estaban: sus roles se quedarían sin ellos y la pantalla daría un 403 sin que nadie hubiera
 * cambiado nada.
 *
 * Solo `owner` y `admin`. El cajero no: una cotización es un compromiso de precio con fecha, y
 * convertirla mueve existencias y dinero. Quien cobra en el mostrador no tiene por qué poder decidir
 * a cuánto se le vende a un cliente.
 */
return new class extends Migration
{
    private const PERMISOS = ['quotes.view', 'quotes.manage', 'quotes.send', 'quotes.convert'];

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

        // Sin esto el permiso queda concedido en la base y NEGADO en la práctica hasta 24 h: spatie
        // cachea el mapa y una inserción con el Query Builder no lo invalida. Ya mordió dos veces.
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
