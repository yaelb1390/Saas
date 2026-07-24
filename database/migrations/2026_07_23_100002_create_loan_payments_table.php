<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abonos/cobros de un préstamo. Cada pago baja el saldo y se aplica a las cuotas más antiguas
 * primero. Es el registro que alimenta el ingreso en Finanzas (como el pago de una venta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('paid_at');
            $table->string('method')->nullable();             // efectivo, transferencia...
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};
