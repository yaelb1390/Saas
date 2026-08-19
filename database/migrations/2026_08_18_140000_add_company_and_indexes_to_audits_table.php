<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La auditoría gana la empresa a la que pertenece, y unos índices por fecha.
 *
 * Se lleva auditando treinta modelos desde el primer día —quién, qué, cuándo, desde qué IP— y no
 * había ni una pantalla que lo leyera. Al construirla aparecen dos carencias de la tabla que trae la
 * librería:
 *
 * 1. NO guarda la empresa. Sin eso, «¿qué pasó en la empresa X?» solo se podría responder uniendo
 *    contra los treinta tipos de modelo auditado, uno por uno.
 * 2. NO tiene índice por fecha: los únicos son `(auditable_type, auditable_id)` y `(user_id,
 *    user_type)`. Ordenar por lo más reciente —que es lo ÚNICO que hace una pantalla de actividad—
 *    obliga a recorrer y ordenar la tabla entera. Con el límite de tiempo de las funciones de
 *    Vercel, eso no es un riesgo teórico.
 *
 * Lo ya registrado se rellena hacia atrás preguntando a la tabla de cada modelo auditado. Lo que no
 * se pueda resolver —una fila de un modelo sin empresa, o de algo ya borrado— se queda en nulo y la
 * pantalla lo enseña como «—»: inventarle una empresa sería peor que no saberlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table): void {
            // Sin clave foránea: la auditoría tiene que SOBREVIVIR al borrado de la empresa —es
            // justo entonces cuando más importa el rastro—, y una foránea con cascada la borraría
            // con ella.
            $table->unsignedBigInteger('company_id')->nullable()->after('id');

            $table->index('created_at');
            $table->index(['company_id', 'created_at']);
        });

        $this->rellenarHaciaAtras();
    }

    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('company_id');
        });
    }

    /**
     * Rellena la empresa de lo ya auditado, tipo por tipo.
     *
     * Se hace con una consulta por tipo de modelo y no fila por fila: son miles de filas y una
     * consulta por cada una agotaría el tiempo de la migración.
     */
    private function rellenarHaciaAtras(): void
    {
        $tipos = DB::table('audits')
            ->select('auditable_type')
            ->distinct()
            ->pluck('auditable_type');

        foreach ($tipos as $tipo) {
            if (! is_string($tipo) || ! class_exists($tipo)) {
                continue;
            }

            $tabla = (new $tipo)->getTable();

            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'company_id')) {
                continue;
            }

            DB::table('audits')
                ->whereNull('company_id')
                ->where('auditable_type', $tipo)
                ->update([
                    'company_id' => DB::raw(
                        "(select company_id from {$tabla} where {$tabla}.id = audits.auditable_id)"
                    ),
                ]);
        }
    }
};
