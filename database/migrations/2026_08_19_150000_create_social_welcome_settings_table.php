<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bienvenida automática a quien escribe por primera vez por Instagram o Facebook.
 *
 * Va en tabla propia y no en `companies.settings` porque no es un interruptor: lleva el texto del
 * mensaje, sus variaciones, las credenciales del webhook y cuántas veces se ha mandado.
 *
 * OJO CON LO QUE ESTO NO ES: no manda nada a quien te sigue. Instagram no lo permite —seguirte no
 * abre la ventana de mensajería, la abre la persona al escribir— y la API de Zernio no tiene
 * siquiera un evento de «nuevo seguidor». Esto contesta a quien YA te escribió, que es cuando se
 * puede y cuando además viene a cuento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_welcome_settings', function (Blueprint $table): void {
            $table->id();
            // Una fila por empresa: la bienvenida es una, no una lista.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->string('message', 900);
            /*
             * Variaciones del mismo mensaje.
             *
             * No es un adorno: Instagram limita las cuentas que mandan el mismo texto una y otra
             * vez, y una bienvenida es, por definición, el mensaje que más se repite de todos.
             */
            $table->json('variations')->nullable();

            /*
             * Credenciales del webhook, por empresa.
             *
             * El `token` va en la URL y es lo ÚNICO que dice de qué empresa es el aviso: nunca se
             * mira un identificador que venga en el cuerpo, que es la regla que ya sigue el webhook
             * de WhatsApp. El `secret` firma el cuerpo (HMAC-SHA256) para que tener la dirección no
             * baste para disparar mensajes en nombre de nadie.
             */
            $table->string('token', 64)->unique();
            /*
             * `text` y no `string`: el secreto va CIFRADO, y el cifrado de Laravel convierte 64
             * caracteres en más de 255. Con `string` PostgreSQL lo rechaza al guardarlo.
             *
             * No lo cazó ningún test: SQLite —que es donde corren— no comprueba la longitud de un
             * varchar, así que los quince pasaban con la columna corta. Salió al abrir la pantalla.
             */
            $table->text('secret');
            // El identificador que Zernio devuelve al darlo de alta, para poder darlo de baja.
            $table->string('zernio_webhook_id')->nullable();

            $table->unsignedInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_welcome_settings');
    }
};
