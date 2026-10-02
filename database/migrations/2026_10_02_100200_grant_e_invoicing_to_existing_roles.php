<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Concede los permisos de Facturación Electrónica (`ecf.*`) a los roles que ya existen.
 *
 * Mismo patrón que `2026_09_25_100009_grant_social_commerce_to_existing_roles`: los permisos se
 * reparten al crear la empresa, así que unos nuevos no llegan solos a las que ya estaban.
 *
 * Solo `owner` y `admin`: configurar, firmar con el certificado de la empresa y anular comprobantes
 * fiscales son decisiones del negocio, no del cajero. El cajero sigue emitiendo desde el punto de
 * venta como hoy; el e-CF lo genera el sistema detrás de esa venta.
 */
return new class extends Migration
{
    private const PERMISOS = [
        'ecf.view', 'ecf.configure', 'ecf.issue', 'ecf.sign', 'ecf.send',
        'ecf.cancel', 'ecf.query', 'ecf.download', 'ecf.audit',
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

        // Sin esto el permiso queda concedido en la base y negado en la práctica hasta 24 h.
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
