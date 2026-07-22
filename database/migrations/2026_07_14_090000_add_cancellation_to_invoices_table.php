<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anulación de comprobantes fiscales.
 *
 * La DGII no permite reutilizar un NCF anulado: el comprobante queda inutilizado y debe
 * reportarse en el formato 608 con su código de anulación. Por eso se conserva la factura
 * (nunca se borra) y solo se marca su estado y motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('cancellation_code', 2)->nullable()->after('status'); // código DGII (01..11)
            $table->string('cancellation_note')->nullable()->after('cancellation_code');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_note');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancellation_code', 'cancellation_note', 'cancelled_at']);
        });
    }
};
