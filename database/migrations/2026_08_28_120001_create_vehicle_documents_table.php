<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los papeles de una unidad: matrícula, factura, seguro, importación, contratos.
 *
 * Se guarda la RUTA del fichero, no el fichero: vive en el mismo disco que las fotos —del servidor en
 * local, Supabase en producción—.
 *
 * `original_name` se conserva porque el nombre con el que se guarda es un identificador aleatorio, y
 * al descargar hay que devolverle al usuario el nombre que él subió. Sin esto, se bajaría un fichero
 * llamado «01JB3K…​.pdf» que nadie sabe qué es.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles') || Schema::hasTable('vehicle_documents')) {
            return;
        }

        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // matricula, factura, seguro, importacion, contrato, otro
            $table->string('type')->default('otro');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'vehicle_id']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
