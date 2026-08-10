<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de opciones de producto: «Tamaño», «Sabor», «Extras».
 *
 * Se eligió este modelo frente a variantes con SKU y stock propios porque en heladería aquel produce
 * explosión del catálogo (3 tamaños × 12 sabores = 36 filas por producto, cada una con su foto y su
 * existencia) y obligaría a tocar StockService, que hoy es el único punto de mutación de stock.
 * Aquí el stock se sigue descontando del producto base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // «single»: el tamaño, uno y solo uno. «multiple»: los sabores, varios a la vez.
            $table->string('selection_type')->default('single');
            $table->boolean('is_required')->default(false);
            // Solo aplican a los grupos múltiples; en los de selección única siempre es 1.
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_groups');
    }
};
