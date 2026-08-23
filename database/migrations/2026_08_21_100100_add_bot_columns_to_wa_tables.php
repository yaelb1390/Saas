<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hace falta en la bandeja para que el bot y las personas convivan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_conversations', function (Blueprint $table): void {
            /*
             * El bot está callado en esta conversación desde esta hora.
             *
             * Es LA pieza del traspaso a una persona. Se pone cuando el cliente pide hablar con
             * alguien o cuando el bot no sabe qué contestar, y no se quita sola: lo hace el dueño
             * desde la bandeja cuando ya atendió.
             *
             * Una fecha y no un booleano porque la pregunta que se hace después es «¿desde cuándo
             * está esperando?», y eso decide a quién se atiende primero.
             */
            $table->timestamp('bot_paused_at')->nullable()->after('last_message_at');
        });

        Schema::table('wa_messages', function (Blueprint $table): void {
            /*
             * Quién escribió: el bot o una persona.
             *
             * `user_id` ya distingue «lo mandó fulano» de «lo mandó el sistema», pero el sistema
             * también manda el enlace del portal del cliente y los avisos. Sin esta columna, el dueño
             * no puede saber qué dijo el bot en su nombre, que es justo lo que va a querer revisar el
             * primer día.
             */
            $table->boolean('sent_by_bot')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('wa_conversations', fn (Blueprint $t) => $t->dropColumn('bot_paused_at'));
        Schema::table('wa_messages', fn (Blueprint $t) => $t->dropColumn('sent_by_bot'));
    }
};
