<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El agregador ÚNICO de métricas: HTTP (Fase 5), trabajos de cola (Fase 4) y consultas lentas de
 * PostgreSQL (Fase 6) escriben aquí, distinguidos por `kind`. Una tabla y no tres, porque las tres
 * preguntas son la misma pregunta —«¿cuánto tarda esto y cuántas veces falla?»— hecha sobre tres
 * cosas distintas, y un histograma, un P95 y una tendencia que ya existen no se reinventan por cada una.
 *
 * NO es una fila por observación: es una fila por (kind, hora, nombre, método, empresa), que SUMA
 * cada observación que le llega. Una fila por petición o por trabajo, con miles de trabajos al día,
 * sería la tabla que hay que podar cada semana para que la base no reviente; agregada por hora, un
 * año entero de datos son unas pocas decenas de miles de filas.
 *
 * El histograma (`h0`..`h8`, nueve tramos fijos) es lo que permite calcular P50/P95/P99 sin guardar
 * cada duración individual: cada observación solo suma UNO al tramo que le toca. Los tramos —en
 * milisegundos, hasta 100/300/1000/3000/10000/30000/60000/180000 y el resto en `h8`— sirven igual
 * para una consulta de 5 ms que para un trabajo de WhatsApp de dos minutos; ver `Histogram::LIMITES`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('metric_buckets')) {
            return;
        }

        Schema::create('metric_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 4); // http|job|db
            $table->timestamp('bucket_start'); // truncado a la hora
            $table->string('name', 150); // ruta, clase del trabajo, o huella de la consulta
            $table->string('method', 20)->nullable(); // verbo HTTP, o la cola (Fase 4)
            $table->string('module', 30)->nullable();
            // 0 y no NULL: PostgreSQL y SQLite tratan NULL como «distinto de cualquier otro NULL»
            // en un índice único, así que dos filas «sin empresa» del mismo minuto no chocarían
            // entre sí y se duplicarían en vez de sumarse. 0 no es un id de empresa válido.
            $table->unsignedBigInteger('company_id')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedBigInteger('sum_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unsignedInteger('h0')->default(0);
            $table->unsignedInteger('h1')->default(0);
            $table->unsignedInteger('h2')->default(0);
            $table->unsignedInteger('h3')->default(0);
            $table->unsignedInteger('h4')->default(0);
            $table->unsignedInteger('h5')->default(0);
            $table->unsignedInteger('h6')->default(0);
            $table->unsignedInteger('h7')->default(0);
            $table->unsignedInteger('h8')->default(0);
            $table->timestamps();

            $table->unique(['kind', 'bucket_start', 'name', 'method', 'company_id'], 'metric_buckets_bucket_unique');
            // La lectura de la pantalla: «lo de este tipo, de los últimos N días».
            $table->index(['kind', 'bucket_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_buckets');
    }
};
