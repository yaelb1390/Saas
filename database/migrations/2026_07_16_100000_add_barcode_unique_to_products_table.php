<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte el índice de código de barras en único por empresa.
 *
 * Escanear exige una resolución determinista: si dos productos de la misma empresa comparten
 * código, la búsqueda devolvería uno u otro según el orden de inserción y el cajero cobraría el
 * artículo equivocado. La unicidad es, por tanto, una regla del negocio, no una optimización.
 *
 * Decisiones:
 *
 * - Único COMPUESTO con company_id, no global: dos empresas distintas pueden vender el mismo
 *   artículo (mismo EAN de fábrica) sin estorbarse.
 * - Los NULL no colisionan entre sí ni en PostgreSQL ni en SQLite, así que los productos sin código
 *   —la mayoría— siguen conviviendo sin tocar nada.
 * - Se elimina el índice simple anterior: el único cubre el mismo prefijo de columnas y sirve igual
 *   para las búsquedas, así que mantener ambos solo costaría escrituras.
 *
 * Es seguro aplicarlo ahora porque la columna existía pero nunca se llegó a escribir (no estaba en
 * las reglas de validación de ningún Form Request): todos los valores son NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'barcode']);
            $table->unique(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'barcode']);
            $table->index(['company_id', 'barcode']);
        });
    }
};
