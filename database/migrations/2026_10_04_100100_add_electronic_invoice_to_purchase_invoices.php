<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5c del e-CF: el comprobante de compras (41) o de gastos menores (43) que la empresa emite al
 * registrar una compra a un proveedor informal. Sin clave foránea: los e-CF se conservan aunque se
 * borre el registro de la compra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_invoices') && ! Schema::hasColumn('purchase_invoices', 'electronic_invoice_id')) {
            Schema::table('purchase_invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('electronic_invoice_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_invoices', 'electronic_invoice_id')) {
            Schema::table('purchase_invoices', function (Blueprint $table): void {
                $table->dropIndex(['electronic_invoice_id']);
                $table->dropColumn('electronic_invoice_id');
            });
        }
    }
};
