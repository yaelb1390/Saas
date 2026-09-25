<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede los permisos de Social Commerce a los roles que ya existen.
 *
 * Mismo motivo que la migración equivalente de `social`: los permisos se reparten al crear la
 * empresa, así que unos nuevos no llegan solos a las que ya estaban.
 *
 * Solo `owner` y `admin`: conectar la cuenta y publicar reglas de automatización habla en nombre
 * del negocio ante sus clientes, igual que en `social`.
 */
return new class extends Migration
{
    private const PERMISOS = ['social_commerce.view', 'social_commerce.manage', 'social_commerce.connect'];

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
        // cachea el mapa y una inserción con el Query Builder no lo invalida.
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
