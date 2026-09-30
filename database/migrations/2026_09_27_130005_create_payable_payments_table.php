<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Abono/pago de una cuenta por pagar. Registro inmutable que baja el saldo y dispara el egreso en
 * Finanzas (misma idea que un gasto, ver App\Modules\Finance\Models\Expense: la cuenta siempre, y
 * el cajón de una caja abierta si la cuenta es de efectivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();

            $table->foreignId('account_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('paid_at');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'payable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_payments');
    }
};
