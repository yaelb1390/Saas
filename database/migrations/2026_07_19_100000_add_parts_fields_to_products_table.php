<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos específicos de repuestos de vehículo sobre el catálogo de productos: número de parte/OEM,
 * marca (fabricante de la pieza), compatibilidad de vehículo (texto simple: marca/modelo/rango de
 * años) y ubicación física en el almacén (pasillo/estante). Todos opcionales: un producto genérico
 * sigue funcionando igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('part_number')->nullable()->after('barcode');
            $table->string('brand')->nullable()->after('part_number');
            $table->string('vehicle_make')->nullable()->after('brand');
            $table->string('vehicle_model')->nullable()->after('vehicle_make');
            $table->unsignedSmallInteger('year_from')->nullable()->after('vehicle_model');
            $table->unsignedSmallInteger('year_to')->nullable()->after('year_from');
            $table->string('location')->nullable()->after('year_to');

            $table->index(['company_id', 'part_number']);
            $table->index(['company_id', 'vehicle_make', 'vehicle_model']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'part_number']);
            $table->dropIndex(['company_id', 'vehicle_make', 'vehicle_model']);
            $table->dropColumn([
                'part_number', 'brand', 'vehicle_make', 'vehicle_model',
                'year_from', 'year_to', 'location',
            ]);
        });
    }
};
