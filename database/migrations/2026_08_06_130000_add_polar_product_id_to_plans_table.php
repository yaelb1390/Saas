<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada plan con su producto en Polar, la pasarela de cobro.
 *
 * Es el primer eslabón para cobrar las suscripciones: sin esta correspondencia, al recibir un pago
 * no habría forma de saber qué plan activarle a la empresa. Se guarda el id del producto y no su
 * nombre porque el nombre lo puede cambiar cualquiera desde el panel de Polar.
 *
 * Nullable: un plan sin enlazar simplemente no se puede contratar en línea, y se sigue asignando a
 * mano como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('polar_product_id')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('polar_product_id');
        });
    }
};
