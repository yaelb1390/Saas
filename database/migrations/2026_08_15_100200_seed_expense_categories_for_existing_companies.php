<?php

declare(strict_types=1);

use App\Modules\Finance\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los conceptos de gasto en las empresas que ya existen.
 *
 * Los conceptos se crean al dar de alta la empresa (FinanceProvisioner), así que las que ya estaban
 * abrirían la pantalla de gastos con el desplegable de conceptos vacío y sin poder anotar nada: el
 * concepto es obligatorio. Sin esto, la función nueva no funcionaría para ningún cliente actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        $empresas = DB::table('companies')->pluck('id');

        foreach ($empresas as $companyId) {
            $existentes = DB::table('expense_categories')
                ->where('company_id', $companyId)
                ->pluck('name')
                ->all();

            $filas = [];

            foreach (ExpenseCategory::INICIALES as $nombre) {
                if (in_array($nombre, $existentes, true)) {
                    continue;
                }

                $filas[] = [
                    'company_id' => $companyId,
                    'name' => $nombre,
                    'is_active' => true,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            if ($filas !== []) {
                DB::table('expense_categories')->insert($filas);
            }
        }
    }

    /**
     * Solo retira los conceptos que NADIE está usando. Uno con gastos detrás no se toca: la marcha
     * atrás de una migración no debería llevarse por delante datos del cliente.
     */
    public function down(): void
    {
        $usados = DB::table('expenses')->distinct()->pluck('expense_category_id');

        DB::table('expense_categories')
            ->whereIn('name', ExpenseCategory::INICIALES)
            ->whereNotIn('id', $usados)
            ->delete();
    }
};
