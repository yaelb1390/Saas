<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «Venta rápida» pasa a ser un módulo propio, y quien ya tenía el punto de venta lo recibe.
 *
 * Sin esto, separar el módulo le quitaría la pantalla a todas las empresas que ya la estaban usando:
 * su plan enumera «pos» y no conoce «quick_pos», así que el middleware las echaría con un 403 sin
 * que nadie hubiera cambiado su contrato. Un módulo nuevo no puede retirar algo ya entregado.
 *
 * Solo se tocan las listas EXPLÍCITAS: `modules = NULL` significa «todos los módulos» y ya incluye
 * el nuevo por definición.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->concederEn('plans');
        $this->concederEn('companies');
    }

    /**
     * Marcha atrás: se retira «quick_pos» de las listas explícitas y queda el estado anterior.
     */
    public function down(): void
    {
        $this->retirarDe('plans');
        $this->retirarDe('companies');
    }

    private function concederEn(string $tabla): void
    {
        foreach (DB::table($tabla)->whereNotNull('modules')->get(['id', 'modules']) as $fila) {
            $modulos = json_decode((string) $fila->modules, true);

            if (! is_array($modulos) || ! in_array('pos', $modulos, true) || in_array('quick_pos', $modulos, true)) {
                continue;
            }

            $modulos[] = 'quick_pos';

            DB::table($tabla)->where('id', $fila->id)->update(['modules' => json_encode(array_values($modulos))]);
        }
    }

    private function retirarDe(string $tabla): void
    {
        foreach (DB::table($tabla)->whereNotNull('modules')->get(['id', 'modules']) as $fila) {
            $modulos = json_decode((string) $fila->modules, true);

            if (! is_array($modulos) || ! in_array('quick_pos', $modulos, true)) {
                continue;
            }

            $modulos = array_values(array_filter($modulos, static fn ($m): bool => $m !== 'quick_pos'));

            DB::table($tabla)->where('id', $fila->id)->update(['modules' => json_encode($modulos)]);
        }
    }
};
