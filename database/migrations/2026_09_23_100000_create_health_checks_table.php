<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El estado de CADA servicio externo, ahora mismo. Una fila por servicio, que se pisa en cada
 * comprobación —esta tabla es «lo último que se sabe», el histórico compacto va en
 * `health_check_results`—.
 *
 * `configured` y `available` son preguntas DISTINTAS, y confundirlas es justo el problema que esta
 * fase viene a arreglar: `PlatformHealthService::integraciones()` decía «bien» con solo mirar si
 * había una clave de API puesta, nunca si el servicio respondía. Una clave de Evolution caducada, un
 * dominio de Polar caído o una cuenta de OpenAI sin saldo se veían tan «bien» como uno que funcionaba
 * de verdad, hasta que un cliente delante del mostrador decía que el bot no contestaba.
 *
 * `status` es el resumen para pintar (healthy|degraded|unhealthy|unknown): lo calcula
 * `HealthAggregator` a partir de lo demás, no se decide aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_checks')) {
            return;
        }

        Schema::create('health_checks', function (Blueprint $table): void {
            $table->id();
            // database|redis|evolution|ai|polar|mail (Fase 3); queue se añade en la Fase 4.
            $table->string('service', 20)->unique();
            $table->string('status', 10)->default('unknown'); // healthy|degraded|unhealthy|unknown
            // ¿Hay credencial puesta? Sin ella no tiene sentido ni intentar la sonda.
            $table->boolean('configured')->default(false);
            // ¿Respondió la última vez que se comprobó? Es la pregunta que antes NO se hacía.
            $table->boolean('available')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 300)->nullable();
            // El último error, YA SANEADO (SecretRedactor): puede llevar la respuesta cruda del
            // proveedor, y esa suele traer detalles que no deben acabar en una pantalla.
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            // Lo propio de cada sonda (p. ej. Polar: si el webhook está configurado). No se
            // estructura en columnas: cada servicio guarda lo suyo y nadie más lo consulta a ciegas.
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
