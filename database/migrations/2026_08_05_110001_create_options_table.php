<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada opción dentro de un grupo: «2 bolas» (+60), «Chocolate» (+0), «Queso extra» (+40).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Lo que suma (o resta) al precio del producto. El tamaño lo usa; el sabor casi nunca.
            $table->decimal('price_delta', 15, 2)->default(0);
            // Previsto para el futuro: permitiría que una opción descuente existencia de otro
            // producto (un topping que sí se inventaría). Hoy nadie lo lee: el stock sale siempre
            // del producto base. Se deja la columna para no necesitar otra migración entonces.
            $table->foreignId('linked_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'option_group_id', 'is_active'], 'options_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('options');
    }
};
