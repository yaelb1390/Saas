<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Icono de la categoría, para la barra lateral del punto de venta.
 *
 * Se guarda el emoji tal cual (una cadena corta) en vez de la clave de un catálogo de SVG: las
 * categorías las inventa cada negocio («Batidas», «Combos», «Promo del día») y un emoji se reconoce
 * de un vistazo sin que nadie tenga que dibujar un icono nuevo por cada idea que se le ocurra al
 * dueño. Nullable: una categoría sin icono cae a uno genérico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('icon', 16)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('icon');
        });
    }
};
