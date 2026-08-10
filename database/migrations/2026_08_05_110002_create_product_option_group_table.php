<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué grupos de opciones ofrece cada producto.
 *
 * Es una tabla pivote y no una columna en `products` porque el mismo grupo se reutiliza en muchos
 * productos: «Sabor» sirve para el cono, la copa y la banana split, y se define una sola vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_group', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_group_id')->constrained()->cascadeOnDelete();
            // Orden en que se le presentan al cajero: primero el tamaño, luego el sabor.
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'option_group_id'], 'product_option_group_unique');
            $table->index(['company_id', 'product_id'], 'product_option_group_company_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_group');
    }
};
