<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por pagar: lo que el negocio todavía debe a un proveedor.
 *
 * Nace sola cuando se recibe una orden de compra (ver
 * App\Modules\Finance\Listeners\CreatePayableFromPurchaseOrder — el propio evento
 * PurchaseOrderReceived ya traía en su docblock la intención de este módulo), o se da de alta a
 * mano para una deuda suelta. `purchase_order_id` es único: una orden no puede tener dos cuentas
 * por pagar abiertas a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code');

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_name')->nullable();

            $table->foreignId('purchase_order_id')->nullable()->unique()->constrained()->nullOnDelete();

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
        Schema::dropIfExists('payables');
    }
};
