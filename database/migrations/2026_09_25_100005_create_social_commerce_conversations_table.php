<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversación de Instagram con una identidad de contacto. Espejo de `wa_conversations`, pero sin
 * tocar el módulo WhatsApp: es una tabla propia con la misma forma, no una extensión de la suya.
 *
 * `rule_id` es la atribución que el CRM no tiene hoy: de qué regla salió este contacto. Se deja
 * NULO al borrar la regla en vez de arrastrar la conversación con ella (auditoría, sección 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_identity_id')->constrained('social_commerce_contact_identities')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('social_commerce_rules')->nullOnDelete();

            $table->string('zernio_conversation_id');
            $table->string('zernio_account_id');
            $table->string('platform', 20)->default('instagram');

            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'zernio_conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_conversations');
    }
};
