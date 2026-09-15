<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El checklist de entrega o de devolución de un alquiler.
 *
 * `checklist` va en JSON (carrocería/cristales/neumáticos/luces/interior/aire/radio/documentos/
 * accesorios → {ok, observación}) y no en una columna por ítem: son etiquetas descriptivas sin lógica
 * de negocio propia, y una columna por cada una obligaría a migrar la tabla cada vez que cambie la
 * lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_rentals') || Schema::hasTable('vehicle_inspections')) {
            return;
        }

        Schema::create('vehicle_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_rental_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // pickup, return
            $table->unsignedInteger('mileage');
            $table->string('fuel_level'); // empty, quarter, half, three_quarters, full
            $table->text('exterior_condition')->nullable();
            $table->text('interior_condition')->nullable();
            $table->text('accessories')->nullable();
            $table->json('checklist')->nullable();
            $table->text('observations')->nullable();
            $table->string('signature_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'vehicle_rental_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
    }
};
