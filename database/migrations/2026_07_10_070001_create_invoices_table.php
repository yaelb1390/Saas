<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facturas fiscales. Cada una lleva un NCF único tomado de una secuencia. Puede originarse
 * de una venta (sale_id) o emitirse de forma independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_sequence_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ncf');
            $table->string('type');                       // B01, B02, ...
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_id')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);     // ITBIS
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status')->default('issued');   // issued, cancelled
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'ncf']);
            $table->unique(['company_id', 'sale_id']);     // una factura por venta
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
