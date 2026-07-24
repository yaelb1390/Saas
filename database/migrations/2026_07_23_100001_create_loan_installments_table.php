<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuotas (calendario de amortización) de un préstamo. Se generan al desembolsar. El estado "vencida"
 * se deriva por fecha (due_date pasada y no pagada); la mora se acumula en late_fee por cuota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');           // # de cuota (1..N)
            $table->date('due_date');
            $table->decimal('amount', 15, 2);                 // capital + interés de la cuota
            $table->decimal('principal_portion', 15, 2)->default(0);
            $table->decimal('interest_portion', 15, 2)->default(0);
            $table->decimal('late_fee', 15, 2)->default(0);   // mora acumulada (ajustable por el admin)
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('status')->default('pending');     // pending, partial, paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'loan_id']);
            $table->index(['company_id', 'due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
