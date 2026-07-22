<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de WhatsApp (entrantes y salientes) de una conversación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wa_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction');                  // inbound, outbound
            $table->string('type')->default('text');
            $table->text('body')->nullable();
            $table->string('status')->default('pending');  // pending, sent, delivered, read, failed, received
            $table->string('external_id')->nullable();     // id del mensaje en Evolution API
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'wa_conversation_id']);
            $table->index(['company_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
    }
};
