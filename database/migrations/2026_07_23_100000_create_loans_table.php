<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Préstamos informales (estilo prestamista RD): capital que la empresa presta a un cliente y cobra
 * en cuotas. El interés lo coloca el administrador (tasa y/o monto); el sistema calcula total y
 * cuota. La mora la controla el administrador por préstamo (late_fee_rate) y por cuota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('customer_name')->nullable();     // snapshot del nombre al prestar
            $table->decimal('principal', 15, 2);             // capital prestado
            $table->decimal('interest_rate', 8, 2)->default(0); // % que coloca el admin (plano)
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);                 // principal + interés
            $table->string('frequency');                     // daily, weekly, biweekly, monthly
            $table->unsignedSmallInteger('installments_count');
            $table->decimal('installment_amount', 15, 2);
            $table->decimal('late_fee_rate', 8, 2)->nullable(); // % de mora sugerida (configurable)
            $table->date('start_date');                      // primer vencimiento
            $table->string('status')->default('active');     // active, paid, cancelled
            $table->decimal('balance', 15, 2);               // saldo pendiente (capital+interés+mora−abonos)
            $table->text('collateral')->nullable();          // garantía
            $table->text('notes')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
