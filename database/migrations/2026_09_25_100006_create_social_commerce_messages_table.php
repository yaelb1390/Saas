<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de una conversación de Instagram. Espejo de `wa_messages`, tabla propia (no toca
 * WhatsApp). Registro de solo-anexo: no lleva `softDeletes`, igual que `wa_messages`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('social_commerce_conversations')->cascadeOnDelete();

            $table->string('direction', 10); // incoming | outgoing
            $table->text('body');
            $table->string('zernio_message_id')->nullable();
            $table->timestamp('sent_at');

            $table->timestamps();

            $table->index(['company_id', 'conversation_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_messages');
    }
};
