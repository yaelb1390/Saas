<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 del e-CF: emitir desde las ventas y facturas.
 *
 *   electronic_invoicing_settings.emission_mode   apagado | sombra | real (ver Domain/EmissionMode).
 *                                                 Nace «apagado»: nada cambia para nadie hasta que el
 *                                                 dueño lo encienda.
 *   invoices.electronic_invoice_id                el e-CF de esa factura (en «real», su comprobante;
 *                                                 en «sombra», la prueba paralela). Sin clave foránea:
 *                                                 los e-CF se conservan aunque la factura se borre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_invoicing_settings') && ! Schema::hasColumn('electronic_invoicing_settings', 'emission_mode')) {
            Schema::table('electronic_invoicing_settings', function (Blueprint $table): void {
                $table->string('emission_mode', 10)->default('apagado');
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'electronic_invoice_id')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('electronic_invoice_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'electronic_invoice_id')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropIndex(['electronic_invoice_id']);
                $table->dropColumn('electronic_invoice_id');
            });
        }

        if (Schema::hasColumn('electronic_invoicing_settings', 'emission_mode')) {
            Schema::table('electronic_invoicing_settings', function (Blueprint $table): void {
                $table->dropColumn('emission_mode');
            });
        }
    }
};
