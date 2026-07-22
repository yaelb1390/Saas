<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hace que la unicidad de SKU y código de barras cuente SOLO entre productos activos.
 *
 * Los productos usan borrado suave (deleted_at): al «borrar» uno, la fila permanece. Con el índice
 * único normal, ese producto borrado seguía ocupando su SKU/código, así que el usuario no podía
 * reutilizarlos —aunque para él el producto ya no existe—. La solución correcta es un índice único
 * PARCIAL (WHERE deleted_at IS NULL): dos productos borrados o un borrado y uno activo pueden
 * compartir SKU; solo se prohíbe el choque entre dos ACTIVOS.
 *
 * PostgreSQL y SQLite soportan índices parciales, así que sirve igual en producción y en las
 * pruebas. La validación de los Form Requests se acota en paralelo con withoutTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'sku']);
            $table->dropUnique(['company_id', 'barcode']);
        });

        DB::statement('CREATE UNIQUE INDEX products_company_sku_active_unique ON products (company_id, sku) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX products_company_barcode_active_unique ON products (company_id, barcode) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX products_company_sku_active_unique');
        DB::statement('DROP INDEX products_company_barcode_active_unique');

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['company_id', 'sku']);
            $table->unique(['company_id', 'barcode']);
        });
    }
};
