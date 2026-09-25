<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de Social Commerce, una fila por empresa (mismo criterio que `wa_bot_settings` y
 * `social_welcome_settings`: no es un interruptor, lleva credenciales y configuración).
 *
 * Social Commerce NO pide una clave de Zernio propia: reutiliza `companies.social_api_key`, la
 * misma cuenta que la empresa ya conectó (ver SOCIAL_COMMERCE_ARCHITECTURE.md, sección 2). Esta
 * tabla solo guarda lo que es exclusivo de este módulo: el webhook propio y el enlace a WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);

            // Número al que se enlaza desde las plantillas con {url_whatsapp}. Formato E.164 sin
            // el signo «+», igual que espera wa.me.
            $table->string('whatsapp_number', 20)->nullable();

            /*
             * Credenciales del webhook propio, mismo patrón que `social_welcome_settings`: el
             * token va en la URL y es lo único que dice de qué empresa es el aviso, el secreto
             * firma el cuerpo. Es un webhook DISTINTO del de `Social` (Fase 2, no se toca el
             * existente): cada uno se da de alta y de baja por su cuenta en Zernio.
             */
            $table->string('webhook_token', 64)->unique();
            $table->text('webhook_secret');
            $table->string('zernio_webhook_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_settings');
    }
};
