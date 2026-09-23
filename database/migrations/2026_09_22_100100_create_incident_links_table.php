<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió un incidente: un grupo de errores, un suceso del sistema o (Fase 3) una
 * comprobación de salud.
 *
 * Un incidente puede tener varios orígenes con el tiempo —el mismo error que lo abrió, y otro
 * relacionado que se detectó después—, así que es una tabla propia y no una columna en `incidents`.
 *
 * `(incident_id, source_type, source_id)` único y no solo `(source_type, source_id)`: un mismo grupo
 * de errores puede reabrir un incidente NUEVO cuando reaparece (`incidents` no autorresuelve), y ese
 * grupo tiene que poder enlazarse otra vez, ahora con el incidente nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incident_links') || ! Schema::hasTable('incidents')) {
            return;
        }

        Schema::create('incident_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('source_type', 20); // error_event|system_event|health_check
            $table->unsignedBigInteger('source_id');
            $table->timestamps();

            $table->unique(['incident_id', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_links');
    }
};
