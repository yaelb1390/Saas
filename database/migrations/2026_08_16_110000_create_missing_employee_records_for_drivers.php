<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le crea la ficha de empleado a los repartidores que se quedaron sin ella.
 *
 * Un usuario con rol «Repartidor» y sin ficha no sirve para nada: no aparece en la lista de
 * repartidores de Entregas —que se saca de los empleados, no de los usuarios— y su portal sale
 * vacío. Se podían crear así, y ya hay alguno creado.
 *
 * A partir de ahora `CompanyUserService` la crea sola; esto repara los que ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driverRoles = DB::table('roles')->where('name', 'driver')->pluck('id');

        if ($driverRoles->isEmpty()) {
            return;
        }

        $userIds = DB::table('model_has_roles')
            ->whereIn('role_id', $driverRoles)
            ->where('model_type', 'App\Models\User')
            ->pluck('model_id');

        foreach ($userIds as $userId) {
            // Ya la tiene: no se toca. Puede haberla creado alguien a mano con otro nombre o cargo.
            $tieneFicha = DB::table('employees')
                ->where('user_id', $userId)->whereNull('deleted_at')->exists();

            if ($tieneFicha) {
                continue;
            }

            $user = DB::table('users')->where('id', $userId)->first();

            if ($user === null || $user->company_id === null) {
                continue;
            }

            DB::table('employees')->insert([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'position' => 'Repartidor',
                'hired_at' => now()->toDateString(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * No se deshace: borrar fichas de empleado se llevaría por delante las entregas que apunten a
     * ellas, y eso es historial de reparto. Que sobre una ficha es inofensivo; que falte, no.
     */
    public function down(): void {}
};
