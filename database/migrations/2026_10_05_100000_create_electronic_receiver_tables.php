<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7a del e-CF: la empresa como RECEPTORA [Descripción Técnica Servicios Emisores Electrónicos,
 * «Estándar como Receptor Electrónico»].
 *
 *   electronic_invoicing_settings.receiver_key   la parte secreta de la dirección de sus servicios de
 *                                                recepción (una por empresa; no adivinable)
 *   electronic_received_documents                cada e-CF que otro contribuyente le envió, con el acuse
 *                                                de recibo (ARECF) que se le devolvió
 *   electronic_invoices.commercial_*             la aprobación comercial (ACECF) que el comprador envió
 *                                                sobre un e-CF propio
 *
 * Sin claves foráneas hacia `companies`: son documentos fiscales y se conservan 10 años [IT §9].
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_invoicing_settings') && ! Schema::hasColumn('electronic_invoicing_settings', 'receiver_key')) {
            Schema::table('electronic_invoicing_settings', function (Blueprint $table): void {
                $table->string('receiver_key', 40)->nullable()->unique();
            });
        }

        if (! Schema::hasTable('electronic_received_documents')) {
            Schema::create('electronic_received_documents', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('environment', 20);
                $table->string('emitter_tax_id', 11)->nullable();
                $table->string('emitter_name')->nullable();
                $table->string('buyer_tax_id', 11)->nullable();
                $table->string('e_ncf', 19)->nullable();
                $table->unsignedTinyInteger('ecf_type')->nullable();
                $table->date('issue_date')->nullable();
                $table->decimal('total', 18, 2)->nullable();
                $table->decimal('itbis_total', 18, 2)->nullable();
                // ARECF: 0 recibido · 1 no recibido; motivo 1 especificación · 2 firma · 3 duplicado ·
                // 4 RNC comprador no corresponde [arecf.xsd].
                $table->unsignedTinyInteger('receipt_status');
                $table->unsignedTinyInteger('receipt_reason')->nullable();
                $table->string('receipt_detail', 500)->nullable();
                $table->string('xml_path')->nullable();
                $table->char('xml_sha256', 64)->nullable();
                $table->string('arecf_path')->nullable();
                $table->char('arecf_sha256', 64)->nullable();
                // Aprobación comercial que la empresa emite sobre este e-CF (fase 7b): 1 aceptado · 2 rechazado.
                $table->unsignedTinyInteger('approval_status')->nullable();
                $table->string('approval_reason', 250)->nullable();
                $table->string('acecf_path')->nullable();
                $table->char('acecf_sha256', 64)->nullable();
                $table->timestamp('approval_sent_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                // Resultado del envío de la aprobación a la DGII y al emisor (fase 7b).
                $table->string('approval_dgii_result', 30)->nullable();
                $table->string('approval_emitter_result', 30)->nullable();
                $table->string('approval_error', 500)->nullable();
                $table->string('sender_ip', 45)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'environment', 'emitter_tax_id', 'e_ncf'], 'ecf_received_lookup');
                $table->index(['company_id', 'created_at']);
            });
        }

        if (Schema::hasTable('electronic_invoices') && ! Schema::hasColumn('electronic_invoices', 'commercial_status')) {
            Schema::table('electronic_invoices', function (Blueprint $table): void {
                // ACECF del comprador sobre este e-CF: 1 aceptado · 2 rechazado [acecf.xsd].
                $table->unsignedTinyInteger('commercial_status')->nullable();
                $table->string('commercial_reason', 250)->nullable();
                $table->timestamp('commercial_at')->nullable();
                // Envío del e-CF aceptado al comprador si es receptor electrónico (fase 7c) [DT p.12]:
                // pendiente · enviado · no_electronico · error; y el acuse (ARECF) que devolvió.
                $table->string('buyer_delivery_status', 20)->nullable();
                $table->unsignedSmallInteger('buyer_delivery_attempts')->default(0);
                $table->timestamp('buyer_delivered_at')->nullable();
                $table->unsignedTinyInteger('buyer_receipt_status')->nullable();
                $table->unsignedTinyInteger('buyer_receipt_reason')->nullable();
                $table->string('buyer_delivery_error', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Solo las que existan: el down no debe fallar sobre una versión anterior de esta migración.
        $columnas = array_values(array_filter([
            'commercial_status', 'commercial_reason', 'commercial_at', 'buyer_delivery_status', 'buyer_delivery_attempts',
            'buyer_delivered_at', 'buyer_receipt_status', 'buyer_receipt_reason', 'buyer_delivery_error',
        ], fn (string $c): bool => Schema::hasColumn('electronic_invoices', $c)));

        if ($columnas !== []) {
            Schema::table('electronic_invoices', function (Blueprint $table) use ($columnas): void {
                $table->dropColumn($columnas);
            });
        }

        Schema::dropIfExists('electronic_received_documents');

        if (Schema::hasColumn('electronic_invoicing_settings', 'receiver_key')) {
            Schema::table('electronic_invoicing_settings', function (Blueprint $table): void {
                $table->dropUnique(['receiver_key']);
                $table->dropColumn('receiver_key');
            });
        }
    }
};
