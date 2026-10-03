<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los e-CF emitidos y su rastro completo.
 *
 * SIN clave foránea en cascada hacia `companies`, a propósito: son documentos fiscales que se
 * conservan 10 años [IT §9] aunque la empresa se borre (decisión del usuario, plan aprobado). Una
 * cascada los borraría con la empresa. El aislamiento lo da `company_id` + el scope de tenant.
 *
 *   electronic_invoices            un e-CF: número, tipo, ambiente, estado, totales, TrackId…
 *   electronic_invoice_files       XML original, firmado, RFCE, respuestas (en el disco privado)
 *   electronic_invoice_responses   cada respuesta de la DGII/proveedor, SOLO se insertan (nunca se
 *                                  reescriben: la historia completa de un documento se conserva)
 *   electronic_invoice_audit_logs  cada cambio de estado y acción, con usuario e IP
 *   electronic_invoice_contingencies  periodos en que no se pudo enviar, y su regularización
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('electronic_invoice_contingencies')) {
            Schema::create('electronic_invoice_contingencies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('environment', 20);
                // sin_conectividad | dgii_no_disponible | sin_generacion [IT §19]
                $table->string('kind', 30);
                $table->string('reason', 500);
                $table->timestamp('started_at');
                $table->timestamp('ended_at')->nullable();
                $table->string('procedure', 500)->nullable();
                $table->timestamp('regularized_at')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'environment', 'ended_at']);
            });
        }

        if (! Schema::hasTable('electronic_invoices')) {
            Schema::create('electronic_invoices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('environment', 20);
                $table->unsignedTinyInteger('ecf_type');
                $table->string('e_ncf', 13);
                $table->string('status', 30);
                // De dónde salió: invoice | sale | purchase_invoice | manual (fase 5).
                $table->string('source_type', 30)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedBigInteger('electronic_ncf_sequence_id')->nullable();
                $table->unsignedBigInteger('contingency_id')->nullable();
                $table->date('issue_date');
                $table->string('buyer_tax_id', 20)->nullable();
                $table->string('buyer_name')->nullable();
                $table->decimal('total', 18, 2);
                $table->decimal('itbis_total', 18, 2)->default(0);
                $table->boolean('sends_summary')->default(false);
                $table->string('security_code', 10)->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->string('certificate_fingerprint', 64)->nullable();
                $table->string('track_id', 100)->nullable();
                $table->string('provider', 20);
                $table->string('spec_version', 10);
                $table->date('schema_date')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('next_attempt_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'environment', 'e_ncf'], 'ecf_number_lookup');
                $table->index(['status', 'next_attempt_at'], 'ecf_pending');
                $table->index(['company_id', 'status', 'created_at']);
                $table->index(['source_type', 'source_id']);
            });
        }

        // Un e-NCF vivo por empresa y ambiente. Único PARCIAL, sin los rechazados: un rechazo con
        // `secuenciaUtilizada = false` devuelve el número al uso [DT p.24] y el documento nuevo lo
        // lleva otra vez, mientras el rechazado se conserva como historia. PostgreSQL y SQLite
        // admiten índices parciales; el constructor de esquemas de Laravel no, de ahí el SQL. Fuera
        // del `if` y con IF NOT EXISTS: si una corrida anterior se cortó tras crear la tabla, se completa.
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS ecf_unique_number ON electronic_invoices (company_id, environment, e_ncf) WHERE status <> 'rechazado'");

        if (! Schema::hasTable('electronic_invoice_files')) {
            Schema::create('electronic_invoice_files', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->foreignId('electronic_invoice_id')->constrained()->restrictOnDelete();
                // original | firmado | rfce | rfce_firmado | respuesta | pdf
                $table->string('kind', 20);
                $table->string('path');
                $table->char('sha256', 64);
                $table->unsignedInteger('bytes');
                $table->timestamps();

                $table->index(['electronic_invoice_id', 'kind']);
            });
        }

        if (! Schema::hasTable('electronic_invoice_responses')) {
            Schema::create('electronic_invoice_responses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->foreignId('electronic_invoice_id')->constrained()->restrictOnDelete();
                // send | send_summary | query
                $table->string('operation', 20);
                $table->string('provider', 20);
                $table->string('outcome', 30);
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('dgii_code', 20)->nullable();
                $table->string('dgii_status', 60)->nullable();
                $table->string('track_id', 100)->nullable();
                $table->json('messages')->nullable();
                $table->boolean('sequence_used')->nullable();
                $table->text('raw')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('electronic_invoice_audit_logs')) {
            Schema::create('electronic_invoice_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('electronic_invoice_id')->nullable()->index();
                $table->string('e_ncf', 13)->nullable();
                $table->string('action', 60);
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('ip', 45)->nullable();
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoice_audit_logs');
        Schema::dropIfExists('electronic_invoice_responses');
        Schema::dropIfExists('electronic_invoice_files');
        Schema::dropIfExists('electronic_invoices');
        Schema::dropIfExists('electronic_invoice_contingencies');
    }
};
