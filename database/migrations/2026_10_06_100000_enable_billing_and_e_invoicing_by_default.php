<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación y Facturación Electrónica, de serie en todos los planes y empresas que ya existen.
 *
 * Solo se tocan las listas explícitas: `modules` NULL ya significa «todos» y se deja tal cual. Se
 * AÑADE lo que falte y nada más; ningún otro módulo cambia. La electrónica nace en «apagado» por
 * empresa, así que tenerla no altera cómo factura nadie hasta que el dueño la configure.
 *
 * Idempotente: correrla dos veces no cambia nada la segunda.
 *
 * `down()` no quita nada: tras la migración no se puede distinguir quién tenía ya estos módulos de
 * quién los recibió aquí, y quitárselos a un cliente que los pagaba sería peor que dejarlos.
 */
return new class extends Migration
{
    private const DE_SERIE = ['billing', 'e_invoicing'];

    public function up(): void
    {
        foreach (['plans', 'companies'] as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'modules')) {
                continue;
            }

            DB::table($tabla)->whereNotNull('modules')->orderBy('id')->select(['id', 'modules'])
                ->chunkById(200, function ($filas) use ($tabla): void {
                    foreach ($filas as $fila) {
                        $actuales = is_array($fila->modules) ? $fila->modules : json_decode((string) $fila->modules, true);

                        if (! is_array($actuales)) {
                            continue;
                        }

                        $faltan = array_values(array_diff(self::DE_SERIE, $actuales));

                        if ($faltan === []) {
                            continue;
                        }

                        DB::table($tabla)->where('id', $fila->id)
                            ->update(['modules' => json_encode(array_values([...$actuales, ...$faltan]))]);
                    }
                });
        }
    }

    public function down(): void
    {
        // A propósito vacío: ver el comentario de la clase.
    }
};
