<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A quién se le dio ya la bienvenida.
 *
 * Es el guardián de que nadie la reciba dos veces, y la unicidad de (empresa, conversación) es TODA
 * la defensa. Sin ella:
 *
 *  · un cliente que escribe tres mensajes seguidos recibe tres bienvenidas;
 *  · y un reintento de Zernio —que reintenta— manda otra encima.
 *
 * Dos mensajes idénticos seguidos de un negocio es exactamente lo que Instagram penaliza, así que
 * esto no es pulcritud: es lo que protege la cuenta del cliente.
 *
 * Se guarda la conversación y no el evento porque lo que hay que responder es «¿ya saludamos a esta
 * persona?», y eso vale para siempre, no solo para un aviso concreto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_welcomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id');
            // Para poder mirar a quién se saludó sin depender de Zernio.
            $table->string('participant_name')->nullable();
            $table->string('platform', 20);
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_welcomes');
    }
};
