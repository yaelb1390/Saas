<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos del cliente (foto de cédula, contrato, etc.). El contenido se guarda en la base
 * (base64) para que persista en el entorno serverless de Vercel sin depender de un disco externo.
 * Pensado para archivos pequeños (límite de subida ~5MB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');                  // nombre visible / archivo original
            $table->string('mime');                  // image/jpeg, application/pdf...
            $table->unsignedInteger('size');         // bytes del archivo original
            $table->longText('content');             // contenido en base64
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
    }
};
