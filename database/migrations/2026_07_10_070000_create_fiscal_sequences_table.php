<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secuencias de NCF autorizadas por la DGII: rango [range_from, range_to] por tipo de
 * comprobante y empresa. La asignación de números es atómica (ver FiscalSequenceService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // prefijo NCF: B01, B02, ...
            $table->unsignedBigInteger('next_number');
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedTinyInteger('number_length')->default(8);
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequences');
    }
};
