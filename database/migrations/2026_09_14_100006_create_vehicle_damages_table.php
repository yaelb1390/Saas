<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un daño encontrado en el vehículo, convertible en cargo del alquiler.
 *
 * `vehicle_rental_id` y `vehicle_inspection_id` van nullable A PROPÓSITO: normalmente un daño se
 * anota durante la inspección de devolución de un alquiler, pero también puede registrarse suelto
 * (una llamada del cliente a mitad de alquiler, o un daño que se descubre después).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_rentals') || Schema::hasTable('vehicle_damages')) {
            return;
        }

        Schema::create('vehicle_damages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_rental_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_inspection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category'); // scratch, dent, glass, tire, interior, paint, mechanical, other
            $table->text('description');
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('responsible')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status')->default('pending'); // pending, charged, waived
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_rental_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_damages');
    }
};
