<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La galería de una unidad.
 *
 * Un carro no se vende con una foto: se venden el frente, el interior, el motor y los golpes. La
 * columna `photo_path` de `vehicles` se queda como la PRINCIPAL —es la que pinta la miniatura de la
 * lista y no conviene resolverla con una consulta por fila—, y aquí viven todas.
 *
 * `position` y no un orden por fecha de subida: el dealer decide cuál va primero, y casi nunca es la
 * que subió antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles') || Schema::hasTable('vehicle_photos')) {
            return;
        }

        Schema::create('vehicle_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Al borrar la unidad se van sus fotos: no significan nada sin el carro.
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_id']);
            // Para pedir la galería ya ordenada sin recorrerla entera.
            $table->index(['vehicle_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photos');
    }
};
