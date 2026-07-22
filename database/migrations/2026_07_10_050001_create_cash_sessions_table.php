<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones de caja (turnos): apertura con fondo, cierre con arqueo (esperado vs contado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');    // open, closed
            $table->decimal('opening_amount', 15, 2)->default(0);
            $table->decimal('expected_amount', 15, 2)->nullable();  // calculado al cerrar
            $table->decimal('counted_amount', 15, 2)->nullable();   // efectivo contado
            $table->decimal('difference', 15, 2)->nullable();       // contado - esperado
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'cash_register_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
