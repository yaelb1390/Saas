<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Hoy no hay».
 *
 * Un producto que no lleva control de existencia —una batida, un sándwich, un servicio— no se puede
 * agotar: se hace al momento. Cuando se acaba el guineo no había forma de quitarlo del terminal salvo
 * desactivarlo en Inventario, y eso lo hace DESAPARECER de la rejilla: el cajero se quedaba sin sitio
 * desde donde volver a encenderlo al día siguiente.
 *
 * Columna propia y no reutilizar `is_active` porque son dos ideas con vidas distintas: `is_active` es
 * «este producto ya no lo vendemos», una decisión que dura; esto es «se acabó», que cambia dos veces
 * al día. Con una sola columna, lo que apagó el cajero a las tres de la tarde sería indistinguible de
 * lo que retiró el dueño el año pasado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_available')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_available');
        });
    }
};
