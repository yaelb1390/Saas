<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada fragmento recuerda con QUÉ se indexó.
 *
 * Sin esto, cambiar de proveedor corrompe la búsqueda EN SILENCIO. Los vectores de proveedores
 * distintos viven en espacios distintos y ni siquiera tienen el mismo tamaño —Gemini 768, OpenAI
 * 1536, el local 128—, y `Vector::cosine` recortaba al más corto sin quejarse: comparaba los
 * primeros 128 componentes de dos espacios sin relación y devolvía un «% afín» calculado sobre puro
 * ruido. Ningún error, ninguna pista, resultados inventados.
 *
 * Con estas dos columnas, la búsqueda solo compara lo que casa con el proveedor de ahora y lo demás
 * se cuenta aparte, para poder decir en pantalla cuántos documentos hay que reindexar.
 *
 * Lo que ya existe se marca como `local`/128, que es exactamente lo que es: hasta hoy no había forma
 * de configurar otra cosa desde el producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_document_chunks', function (Blueprint $table): void {
            $table->string('provider')->default('local')->after('position');
            $table->unsignedSmallInteger('dimensions')->default(128)->after('provider');
        });

        DB::table('ai_document_chunks')->update(['provider' => 'local', 'dimensions' => 128]);
    }

    public function down(): void
    {
        Schema::table('ai_document_chunks', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'dimensions']);
        });
    }
};
