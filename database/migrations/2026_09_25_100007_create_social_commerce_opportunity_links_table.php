<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De qué automatización salió una oportunidad del CRM.
 *
 * En vez de añadir una columna `source` a `opportunities` —que tocaría el módulo CRM—, la
 * atribución vive en esta tabla puente propia (auditoría, sección 16.1 / arquitectura, sección 4).
 * `opportunities` no se entera de que existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_opportunity_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('social_commerce_conversations')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('social_commerce_rules')->nullOnDelete();

            $table->timestamps();

            $table->unique('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_opportunity_links');
    }
};
