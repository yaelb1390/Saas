<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprobantes de COMPRA que el negocio recibe de sus proveedores (facturas con NCF). Se registran
 * para armar el envío 606 de la DGII. El adjunto (foto/PDF) se guarda en base64 (como los documentos
 * de cliente), para persistir sin disco externo. Las columnas siguen el formato 606.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Proveedor + comprobante
            $table->string('provider_tax_id')->nullable();          // RNC/Cédula del proveedor
            $table->string('provider_tax_id_kind')->default('1');   // TaxIdKind: 1 RNC, 2 Cédula, 3 Pasaporte
            $table->string('provider_name')->nullable();
            $table->string('ncf');
            $table->string('ncf_modified')->nullable();             // NCF modificado (notas de crédito/débito)
            $table->string('goods_services_type')->default('09');   // tipo de bienes/servicios DGII 01–11

            // Fechas
            $table->date('invoice_date');
            $table->date('payment_date')->nullable();

            // Montos (formato 606)
            $table->decimal('amount', 15, 2)->default(0);           // monto facturado sin ITBIS
            $table->decimal('itbis', 15, 2)->default(0);            // ITBIS facturado
            $table->decimal('itbis_retenido', 15, 2)->default(0);
            $table->decimal('isr_retenido', 15, 2)->default(0);
            $table->decimal('isc', 15, 2)->default(0);              // impuesto selectivo al consumo
            $table->decimal('other_taxes', 15, 2)->default(0);
            $table->decimal('tip', 15, 2)->default(0);              // propina legal
            $table->string('payment_method')->default('cash');      // efectivo, transfer, card, credit, other

            // Adjunto (foto/PDF) en base64
            $table->string('file_name')->nullable();
            $table->string('file_mime')->nullable();
            $table->unsignedInteger('file_size')->default(0);
            $table->longText('file_content')->nullable();

            // Preparado para la extracción con IA (hoy: manual)
            $table->string('extraction_status')->default('manual'); // manual | extracted
            $table->json('raw_extraction')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'ncf']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
