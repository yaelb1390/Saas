<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6: qué consultas de PostgreSQL son lentas, de verdad y de forma agrupada.
 *
 * UNA fila por PATRÓN de consulta (`fingerprint`, el SQL sin sus valores), no por ejecución: la
 * misma consulta lenta corriendo mil veces con ids distintos es una fila con `hits=1000`, no mil
 * filas. `sql_sample` es la sentencia YA sin bindings —`MessageNormalizer::normalizeSql()`, la misma
 * función que usa la huella de errores desde la Fase 1a— para que nunca se guarde un dato real de
 * negocio (un email, una cédula) que viajara como valor de un `where`.
 *
 * `last_company_id` es informativo (quién la disparó por última vez), no una clave de reparto: la
 * MISMA consulta la puede lanzar cualquier empresa, así que no hay «hijas por empresa» como en
 * `error_events` — por lo mismo NO lleva columna `company_id` a secas (ver `TenantPurgeCompletenessTest`,
 * que exige decidir qué hacer con cada tabla que sí la lleve).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('slow_queries')) {
            return;
        }

        Schema::create('slow_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique(); // sha256 del SQL normalizado
            $table->string('sql_sample', 1000);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unsignedInteger('last_ms')->default(0);
            $table->string('last_route', 150)->nullable();
            $table->unsignedBigInteger('last_company_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            // La lectura de la pantalla: «las más repetidas» o «las más viejas para podar».
            $table->index('hits');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slow_queries');
    }
};
