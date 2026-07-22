<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oportunidades (negocios) del CRM: avanzan por las etapas del pipeline hasta ganarse o perderse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pipeline_id')->constrained()->restrictOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->string('title');
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status')->default('open');     // open, won, lost
            $table->date('expected_close_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'pipeline_id', 'stage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};
