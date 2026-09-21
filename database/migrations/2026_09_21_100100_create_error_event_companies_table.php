<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El desglose por empresa de cada error: «Empresa ABC 47 veces, Empresa XYZ 31…».
 *
 * `error_events` guarda cada error una sola vez con el total de veces que ocurrió. Sin esta tabla, un
 * fallo que afecta a doce empresas se veía como «148 veces» sin poder decir a quiénes ni cuántas veces a
 * cada una: la empresa vivía en una sola columna que cada repetición pisaba.
 *
 * Una fila por (error, empresa). `hits` suma las veces de ESA empresa; lo que no se pudo atribuir a
 * ninguna (consola, plataforma, un visitante sin sesión) es lo que sobra de restar estas filas al total
 * del grupo.
 *
 * `company_id` SIN clave foránea, como en el resto de tablas de registro: el rastro de una empresa
 * borrada no debe desaparecer por una cascada, y borrarlo es decisión de `CompanyEraser`. La clave
 * foránea SÍ existe hacia el error, porque una fila de desglose sin su error no significa nada, y así
 * podarlo (`registros:purgar`) se lleva también el desglose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('error_event_companies') || ! Schema::hasTable('error_events')) {
            return;
        }

        Schema::create('error_event_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('error_event_id')->constrained('error_events')->cascadeOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            // Sumar una ocurrencia es un UPDATE sobre exactamente esta pareja, y la restricción es lo
            // que impide que dos peticiones a la vez creen la misma fila dos veces.
            $table->unique(['error_event_id', 'company_id']);

            // «¿Qué errores tiene esta empresa?»: la ficha de la empresa y el borrado de la empresa.
            $table->index(['company_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_event_companies');
    }
};
