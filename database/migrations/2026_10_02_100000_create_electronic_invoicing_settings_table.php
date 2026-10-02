<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de facturación electrónica (e-CF), una fila por empresa.
 *
 * Aparte de `companies` a propósito: los datos fiscales que van en el e-CF (razón social, RNC,
 * municipio y provincia con los códigos de la DGII) no siempre coinciden con lo que sale en el
 * recibo de mostrador, y aquí viven además el ambiente, el estado y la configuración del proveedor,
 * que es una credencial.
 *
 * Idempotente: en producción las migraciones se aplican a mano y el código sale antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('electronic_invoicing_settings')) {
            return;
        }

        Schema::create('electronic_invoicing_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            // Datos fiscales del emisor tal como irán en el e-CF. Columnas amplias a propósito: las
            // longitudes exactas las impone el XSD oficial al validar, no la base de datos (si la DGII
            // las cambia, no hace falta migrar).
            $table->string('tax_id', 11)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('address')->nullable();
            // Códigos de la DGII; se validan contra sus tablas en el paso de datos fiscales.
            $table->string('municipality', 20)->nullable();
            $table->string('province', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();

            // Cadenas cortas en vez de enums de base de datos: los valores los fija la aplicación.
            $table->string('environment', 20)->default('pruebas');
            $table->string('status', 30)->default('no_configurado');
            $table->string('provider', 20)->default('fake');
            // Credenciales del proveedor: cifradas por el modelo (cast `encrypted`).
            $table->text('provider_config')->nullable();
            $table->string('ecf_admin_user', 100)->nullable();
            $table->string('spec_version', 10)->default('1.0');
            $table->timestamp('last_dgii_contact_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_invoicing_settings');
    }
};
