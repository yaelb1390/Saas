<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La galería de UNA inspección (entrega o devolución).
 *
 * Tabla propia del módulo Alquiler, y no una extensión de `vehicle_photos` del Dealer: ese módulo no
 * tiene por qué saber que el de Alquiler existe. Es la misma razón por la que Alquiler sí puede leer
 * `vehicles` (depende de Vehículos) pero no al revés.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_inspections') || Schema::hasTable('vehicle_inspection_photos')) {
            return;
        }

        Schema::create('vehicle_inspection_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_inspection_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'vehicle_inspection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspection_photos');
    }
};
