<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de lo que pasa en el sistema y no deja rastro en ninguna otra parte.
 *
 * NO duplica lo que ya hay:
 *
 *  · `audits` guarda quién creó, cambió o borró algo —treinta modelos—.
 *  · `error_events` guarda las excepciones, agrupadas por huella.
 *
 * Esto guarda lo que no es ninguna de las dos cosas y hasta ahora se perdía: quién entró y desde
 * dónde, los intentos fallidos, los fallos de los servicios externos, las acciones del operador
 * sobre una empresa, las tareas programadas y los webhooks que llegan.
 *
 * El agujero más grande que tapa: hasta hoy NO QUEDABA RASTRO DE NINGÚN INICIO DE SESIÓN. No había
 * forma de saber quién entró en la cuenta de un cliente, ni de ver que alguien lleva doscientos
 * intentos fallidos contra ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();

            /*
             * Los dos, nulos a propósito.
             *
             * Un intento de acceso fallido ocurre ANTES de saber de qué empresa es —a veces con un
             * correo que no existe—, y las acciones del operador de la plataforma no son de ninguna.
             * Exigirlos dejaría fuera justo lo que más importa registrar.
             *
             * Sin clave foránea con borrado en cascada: el rastro de una empresa borrada es lo que
             * queda para explicar qué pasó, y una cascada se lo llevaría con ella.
             */
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // Qué pasó, en forma de clave estable: `auth.login`, `integration.failed`,
            // `platform.company_suspended`… Se filtra por aquí, así que no es texto libre.
            $table->string('type', 60);
            // `info` | `warning` | `critical`. Lo que decide qué se mira primero.
            $table->string('level', 10)->default('info');
            $table->string('message', 300);
            // El detalle. Se limpia de claves ANTES de guardarlo.
            $table->json('context')->nullable();

            // Quién y desde dónde. En un intento fallido es lo único que hay.
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            // Todas las consultas de la pantalla ordenan por lo más reciente y filtran por uno de
            // estos tres. Sin índices, con la tabla creciendo cada día, esa pantalla se cae sola.
            $table->index('created_at');
            $table->index(['type', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
