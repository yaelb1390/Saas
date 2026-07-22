<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos configurables del POS a nivel de ticket: propina (se suma al total pero NO se grava con
 * ITBIS), descuento global del ticket y el empleado que atendió la venta. Todos opcionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('tip', 15, 2)->default(0)->after('total');
            $table->decimal('discount_total', 15, 2)->default(0)->after('tip');
            $table->foreignId('employee_id')->nullable()->after('user_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['tip', 'discount_total']);
        });
    }
};
