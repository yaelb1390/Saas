<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de caja dentro de una sesión (cobros de ventas, ingresos, gastos, retiros).
 * `amount` se guarda con signo; el saldo de caja es opening_amount + suma de amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->constrained()->cascadeOnDelete();
            $table->string('type');                       // sale, income, deposit, expense, withdrawal
            $table->decimal('amount', 15, 2);             // con signo: + ingreso, - egreso
            $table->nullableMorphs('reference');          // venta u otro documento origen
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'cash_session_id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
