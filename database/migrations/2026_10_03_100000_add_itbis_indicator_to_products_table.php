<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indicador de facturación del producto para el e-CF [FMT, sección B, campo 4].
 *
 * Por omisión 1 (ITBIS 18 %): es exactamente lo que BMIA hace hoy con todos los productos, así que
 * añadir la columna no cambia nada para nadie. Solo quien marque un producto como exento o a otra
 * tasa verá la diferencia, y solo en los comprobantes electrónicos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'itbis_indicator')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedTinyInteger('itbis_indicator')->default(1);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'itbis_indicator')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn('itbis_indicator');
            });
        }
    }
};
