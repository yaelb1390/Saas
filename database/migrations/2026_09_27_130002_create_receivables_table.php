<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por cobrar: lo que un cliente todavía debe.
 *
 * Nace sola cuando una venta se completa sin cobrarse del todo (ver
 * App\Modules\Finance\Listeners\CreateReceivableFromSale), o se da de alta a mano para una deuda
 * suelta que no viene de una venta del sistema. `sale_id` es único: una venta no puede tener dos
 * cuentas por cobrar abiertas a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot: si la ficha del cliente cambia de nombre después, el papel de esta deuda no.
            $table->string('customer_name')->nullable();

            $table->foreignId('sale_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->decimal('total', 15, 2);
            $table->decimal('balance', 15, 2);
            $table->date('due_date')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
