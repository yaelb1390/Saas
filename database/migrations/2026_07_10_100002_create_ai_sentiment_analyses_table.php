<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Análisis de sentimiento sobre cualquier entidad (p. ej. mensajes de WhatsApp), vía relación
 * polimórfica. Mantiene la IA desacoplada del esquema de los otros módulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sentiment_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->morphs('analyzable');
            $table->string('sentiment');                  // positive, neutral, negative
            $table->decimal('score', 5, 4)->default(0);
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'sentiment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sentiment_analyses');
    }
};
