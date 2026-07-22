<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fragmentos (chunks) de un documento con su embedding.
 *
 * El embedding se guarda como JSON para funcionar sobre PostgreSQL estándar (y SQLite en tests).
 * Para escalar, se puede migrar la columna a `vector` de pgvector y sustituir el cálculo de
 * similitud en PHP por un índice ANN en la base de datos (ver RagService::retrieve).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->longText('content');
            $table->json('embedding');
            $table->timestamps();

            $table->index(['company_id', 'ai_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_document_chunks');
    }
};
