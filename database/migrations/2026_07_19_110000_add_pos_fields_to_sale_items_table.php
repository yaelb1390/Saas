<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos configurables del POS por línea de venta: descuento (monto ya resuelto), nota libre,
 * número de serie/IMEI y el empleado que atendió/vendió esa línea (base para comisiones). Todos
 * opcionales: las ventas existentes y los negocios que no usan estas opciones no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0)->after('unit_price');
            $table->string('note')->nullable()->after('subtotal');
            $table->string('serial')->nullable()->after('note');
            $table->foreignId('employee_id')->nullable()->after('serial')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['discount', 'note', 'serial']);
        });
    }
};
