<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El histórico de comprobaciones, compacto: una fila por sonda ejecutada, no por segundo.
 *
 * `health_checks` dice «cómo está AHORA»; esta tabla dice «cómo ha estado» —para la disponibilidad
 * observada de 24 h / 7 d y para ver si un servicio degradado lleva así cinco minutos o cinco horas—.
 * Se poda a los 14 días (`registros:purgar`): pasado ese tiempo no aporta nada que la disponibilidad
 * ya resumida no diga mejor.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_check_results')) {
            return;
        }

        Schema::create('health_check_results', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 20);
            $table->string('status', 10); // healthy|degraded|unhealthy|unknown
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 300)->nullable();
            // cron|manual|panel: quién disparó la comprobación, para poder leer un patrón raro
            // («¿por qué hay tantas "manual" seguidas?») sin adivinar.
            $table->string('trigger', 10);
            $table->timestamp('created_at');

            // La serie de un servicio en el tiempo: es la única consulta que hace esta tabla.
            $table->index(['service', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_results');
    }
};
