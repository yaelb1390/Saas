<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un incidente: «esto se rompió, y sigue roto (o ya no)», con su propio código.
 *
 * Un grupo de errores (`error_events`) dice «esto está pasando»; un incidente dice «esto es lo
 * bastante grave como para que alguien lo mire, y esto es lo que se sabe de él». Puede nacer de un
 * error que se repite mucho o que afecta a muchas empresas (Fase 2) o de un servicio caído (Fase 3);
 * `incident_links` guarda de dónde salió.
 *
 * `dedupe_key` (`error:{fingerprint}` o `health:{servicio}`) es lo que impide abrir dos incidentes
 * para el mismo problema mientras el primero sigue abierto: el índice único de abajo solo se aplica
 * MIENTRAS el incidente está activo (`open`/`investigating`). En cuanto se resuelve o se ignora, la
 * clave queda libre otra vez —si el mismo problema reaparece, es un incidente NUEVO, con su propio
 * código: no hay autorresolución de errores que pueda decidir por el operador que el anterior seguía
 * siendo el mismo problema—.
 *
 * `year`+`seq` dan el código legible (`INC-2026-0094`); van en columnas propias y no solo dentro del
 * texto para poder calcular «el siguiente número de este año» con un `MAX` indexado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incidents')) {
            return;
        }

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('seq');
            $table->string('title', 200);
            // El mismo vocabulario que `error_events.service` / `ServiceResolver::NOMBRES`.
            $table->string('service', 30)->nullable();
            $table->string('severity', 8)->default('medium'); // low|medium|high|critical
            $table->string('status', 14)->default('open'); // open|investigating|resolved|ignored
            $table->timestamp('started_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedInteger('companies_count')->default(0);
            $table->text('description')->nullable();
            $table->text('cause')->nullable();
            $table->text('resolution')->nullable();
            $table->string('dedupe_key', 150)->nullable();
            $table->string('source', 6)->default('auto'); // manual|auto
            // Sin clave foránea: quien lo creó o resolvió puede dejar de existir y el incidente sigue.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->unique(['year', 'seq']);
            // La pantalla lista «los que siguen abiertos, por lo último que se detectó» y filtra por servicio.
            $table->index(['status', 'last_detected_at']);
            $table->index(['service', 'last_detected_at']);
        });

        // Índice único PARCIAL: solo mientras el incidente está activo. Válido en SQLite y en
        // PostgreSQL con la misma sintaxis (los dos soportan `CREATE UNIQUE INDEX ... WHERE ...`),
        // así que no hace falta una rama por motor.
        if (! Schema::hasIndex('incidents', 'incidents_dedupe_activo_unique')) {
            try {
                DB::statement(
                    'CREATE UNIQUE INDEX incidents_dedupe_activo_unique ON incidents (dedupe_key) '
                    ."WHERE status IN ('open', 'investigating')"
                );
            } catch (Throwable) {
                // Si ya existe con otro nombre (o el motor no lo admite), no se rompe el despliegue:
                // el `dedupe` en `IncidentService` vuelve a comprobar antes de insertar.
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
