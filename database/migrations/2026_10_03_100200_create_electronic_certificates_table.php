<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificados digitales con los que cada empresa firma sus e-CF.
 *
 * El archivo .p12 NO vive aquí: se guarda cifrado en el disco privado `fiscal_documents`. Aquí solo
 * van la ruta, la contraseña CIFRADA (cast `encrypted` en el modelo) y los datos públicos del
 * certificado para enseñar y avisar del vencimiento. Un reemplazo no borra el anterior: se desactiva,
 * para saber con qué certificado se firmó cada documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_certificates')) {
            return;
        }

        Schema::create('electronic_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->text('password');
            $table->string('subject', 500);
            $table->string('issuer', 500);
            $table->string('serial', 100);
            $table->string('fingerprint', 64);
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->boolean('is_active')->default(true);
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_certificates');
    }
};
