<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El desglose por empresa de un incidente: «Empresa ABC, 12 veces».
 *
 * Igual que `error_event_companies` con los errores, y sobrevive a su poda: `registros:purgar` borra
 * grupos de error viejos (`error_events.last_seen_at`), pero un incidente es un hecho que ya pasó y
 * el historial de a quién afectó no debe desaparecer solo porque el error que lo originó se podó.
 *
 * `company_id` SIN clave foránea, como el resto de tablas de registro: el rastro de una empresa
 * borrada no desaparece por una cascada, es decisión de `CompanyEraser`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incident_companies') || ! Schema::hasTable('incidents')) {
            return;
        }

        Schema::create('incident_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['incident_id', 'company_id']);
            $table->index(['company_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_companies');
    }
};
