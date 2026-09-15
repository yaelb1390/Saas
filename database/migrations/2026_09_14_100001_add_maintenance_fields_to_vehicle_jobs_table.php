<?php

declare(strict_types=1);

use App\Modules\Core\Support\DbTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deja que un trabajo de taller sirva también como mantenimiento PROGRAMADO.
 *
 * `vehicle_jobs` ya es «cualquier gasto que sube el costo real» (reparación, importación, transporte,
 * documentación...). No hace falta una segunda tabla para «mantenimiento»: hace falta que ESTE
 * registro pueda decir en qué kilometraje se hizo y cuándo/con cuántos km toca el siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_jobs')) {
            return;
        }

        Schema::table('vehicle_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('vehicle_jobs', 'mileage')) {
                $table->unsignedInteger('mileage')->nullable()->after('cost');
            }

            if (! Schema::hasColumn('vehicle_jobs', 'next_due_at')) {
                $table->date('next_due_at')->nullable()->after('performed_at');
            }

            if (! Schema::hasColumn('vehicle_jobs', 'next_mileage')) {
                $table->unsignedInteger('next_mileage')->nullable()->after('next_due_at');
            }
        });

        DbTable::olvidar();
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicle_jobs')) {
            return;
        }

        Schema::table('vehicle_jobs', function (Blueprint $table): void {
            foreach (['mileage', 'next_due_at', 'next_mileage'] as $columna) {
                if (Schema::hasColumn('vehicle_jobs', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        DbTable::olvidar();
    }
};
