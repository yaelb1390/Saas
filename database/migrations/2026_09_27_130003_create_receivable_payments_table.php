<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abono/cobro de una cuenta por cobrar. Registro inmutable que baja el saldo y dispara el ingreso
 * en Finanzas (misma idea que LoanPayment, ver App\Modules\Loans\Models\LoanPayment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();

            // A qué cuenta financiera entró el dinero. `restrictOnDelete`: borrar una cuenta con
            // cobros dejaría entradas de dinero sin destino.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 2);
            // Saldo de la cuenta por cobrar justo después de este abono: congelado para que un
            // recibo reimpreso más tarde siga diciendo lo que se debía aquel día.
            $table->decimal('balance_after', 15, 2);
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('paid_at');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'receivable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
