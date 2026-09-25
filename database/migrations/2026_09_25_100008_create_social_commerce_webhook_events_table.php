<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de los avisos recibidos del webhook propio de Social Commerce.
 *
 * Misma forma que `polar_webhook_events` a propósito (auditoría, sección 5 y 16): el índice único
 * sobre `event_id` es la idempotencia, no una comprobación previa — el manejador inserta primero y
 * confía en que la base rechace el duplicado (ver `PolarWebhookHandler::claim()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('result')->default('received'); // received | applied | ignored | unresolved

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_webhook_events');
    }
};
