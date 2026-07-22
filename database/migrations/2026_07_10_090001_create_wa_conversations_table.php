<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversaciones de WhatsApp (una por número de teléfono y empresa). Se enlaza al cliente del
 * CRM cuando el teléfono coincide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->string('name')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_conversations');
    }
};
