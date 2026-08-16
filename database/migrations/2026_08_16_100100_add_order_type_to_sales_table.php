<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cómo se lleva el cliente lo que compró: para comer aquí, para llevar o con envío.
 *
 * NULLABLE a propósito. Las ventas que ya existen no se pueden clasificar sin inventarse el dato, y un
 * valor por omisión las marcaría a todas como «para comer aquí»: los informes dirían que un colmado
 * tiene comedor. Nulo significa «no se preguntó», que es exactamente lo que pasó.
 *
 * También queda nulo cuando el negocio no enciende la opción, que es el caso de una ferretería.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->string('order_type', 20)->nullable()->after('status');

            // Para «¿cuánto vendemos para llevar este mes?», que es la razón de guardarlo.
            $table->index(['company_id', 'order_type']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'order_type']);
            $table->dropColumn('order_type');
        });
    }
};
