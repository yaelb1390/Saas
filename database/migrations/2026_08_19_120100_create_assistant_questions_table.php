<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que preguntan los usuarios al asistente.
 *
 * Se guarda por dos motivos, y el segundo vale más que el primero:
 *
 * 1. El TOPE DIARIO por empresa se cuenta de aquí. Podría llevarse en caché, pero en Vercel la caché
 *    es la propia base de datos (CACHE_STORE=database), así que no se ahorraría nada y se perdería
 *    todo lo demás.
 *
 * 2. Las preguntas que NO encontraron respuesta —`answered_by = 'nada'`— son la lista de lo que los
 *    clientes necesitan y el manual no cubre. Sin esto, un artículo que falta no se descubre nunca:
 *    el usuario pregunta, no encuentra, y se calla.
 *
 * NO se guarda la respuesta. Ocupa mucho, se puede volver a generar, y lo que hace falta para decidir
 * qué escribir es la pregunta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Sin usuario si la cuenta se borró después: la pregunta sigue diciendo qué falta en el
            // manual, que es para lo que se guardó.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('question', 500);
            /*
             * Cómo se resolvió:
             *   ia            → la redactó el proveedor a partir del manual
             *   articulo      → sin clave de API o con el proveedor caído: se enseñó el artículo
             *   fuera_de_plan → existe, pero su plan no lo incluye
             *   sin_permiso   → existe y lo tiene, pero ese usuario no puede hacerlo
             *   nada          → el manual no lo cubre. ESTE es el que hay que mirar.
             */
            $table->string('answered_by', 20);
            $table->string('article_slug')->nullable();
            $table->timestamp('created_at')->nullable();

            // El tope diario consulta exactamente por esto: una empresa y un día.
            $table->index(['company_id', 'created_at']);
            // Y el listado de huecos del manual, por lo más reciente.
            $table->index(['answered_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_questions');
    }
};
