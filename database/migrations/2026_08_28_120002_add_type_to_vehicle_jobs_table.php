<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El tipo de gasto de la unidad.
 *
 * `vehicle_jobs` nació como «trabajos de taller», pero el costo real de un carro no es solo lo que se
 * le arregla: es la importación, el transporte, la matriculación y los papeles. Sin distinguirlos,
 * el ejemplo del dealer —620.000 de compra + 25.000 de importación + 15.000 de transporte + 20.000 de
 * reparaciones + 10.000 de documentación— no se puede registrar.
 *
 * SE AÑADE UNA COLUMNA EN VEZ DE UNA TABLA NUEVA. Un «gasto de importación» y una «reparación»
 * tienen exactamente la misma forma: descripción, costo, fecha y quién. Dos tablas iguales darían dos
 * sitios donde sumar el costo real, y el día que alguien sume solo en uno, el margen miente sin
 * avisar. La pantalla de Taller pasa a filtrar los de tipo «reparación».
 *
 * Lo que ya estaba se marca como reparación, que es lo que era.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_jobs') || Schema::hasColumn('vehicle_jobs', 'type')) {
            return;
        }

        Schema::table('vehicle_jobs', function (Blueprint $table): void {
            $table->string('type')->default('reparacion')->after('vehicle_id');
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicle_jobs') && Schema::hasColumn('vehicle_jobs', 'type')) {
            Schema::table('vehicle_jobs', function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'type']);
                $table->dropColumn('type');
            });
        }
    }
};
