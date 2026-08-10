<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice para la rejilla del punto de venta táctil.
 *
 * Esa pantalla pide siempre lo mismo: los productos activos de una categoría dentro de la empresa
 * activa, ordenados por nombre. Sin este índice, cada toque de un chip de categoría obliga a un
 * recorrido completo de `products`, y es la consulta más repetida de toda la jornada de venta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->index(['company_id', 'category_id', 'is_active'], 'products_catalog_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_catalog_index');
        });
    }
};
