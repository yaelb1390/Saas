<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enciende «Tamaños y sabores» en las empresas que YA lo estaban usando.
 *
 * La función pasa a estar apagada por defecto —el menú de un colmado no tiene por qué llevar una
 * entrada de sabores de helado—, pero apagarla a todo el mundo se llevaría por delante la pantalla de
 * las heladerías que la usan a diario, sin avisar y sin que hubieran cambiado nada.
 *
 * El criterio es el único que no se puede discutir: si la empresa tiene grupos de opciones creados,
 * es que la usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conGrupos = DB::table('option_groups')->distinct()->pluck('company_id');

        foreach ($conGrupos as $companyId) {
            $this->marcar((int) $companyId, true);
        }
    }

    /**
     * Marcha atrás: se retira la marca de las que la migración encendió. No se toca nada más, para
     * no apagársela a quien la haya activado por su cuenta después.
     */
    public function down(): void
    {
        foreach (DB::table('option_groups')->distinct()->pluck('company_id') as $companyId) {
            $this->marcar((int) $companyId, false);
        }
    }

    private function marcar(int $companyId, bool $valor): void
    {
        $fila = DB::table('companies')->where('id', $companyId)->first(['settings']);

        if ($fila === null) {
            return;
        }

        $ajustes = json_decode((string) ($fila->settings ?? '{}'), true);

        if (! is_array($ajustes)) {
            $ajustes = [];
        }

        $ajustes['features']['option_groups'] = $valor;

        DB::table('companies')->where('id', $companyId)->update([
            'settings' => json_encode($ajustes),
            'updated_at' => now(),
        ]);
    }
};
