<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto espera el bot antes de contestar, y cuánto tiempo se guarda lo que se habló.
 *
 * Los dos son decisiones del negocio, no de la plataforma, y por eso son columnas y no constantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            /*
             * Segundos que se espera por si el cliente sigue escribiendo.
             *
             * Nadie escribe párrafos por WhatsApp: escribe «hola» / «buenas» / «tienen batidas?» en
             * tres mensajes seguidos. Contestando a cada uno, el bot suelta tres respuestas —dos a un
             * saludo— y encima paga tres llamadas al modelo para una sola pregunta.
             *
             * Ocho segundos porque es lo que se tarda en escribir la segunda frase sin que el cliente
             * llegue a pensar que no le contestan. Cada negocio lo ajusta: una ferretería que recibe
             * pedidos largos querrá más margen que un colmado.
             *
             * CERO desactiva la espera y contesta al momento, como se hacía antes. Sigue siendo lo
             * correcto cuando no hay worker: sin él el aplazamiento no aplaza nada.
             */
            $table->unsignedSmallInteger('group_seconds')->default(8)->after('includes_plans');

            /*
             * Días que se conservan los mensajes antes de borrarlos.
             *
             * CERO —el valor por omisión— significa NO BORRAR NUNCA, y es deliberado que sea así.
             *
             * Poner aquí noventa días habría borrado el historial de todos los negocios que ya usan
             * esto, sin que nadie lo pidiera y sin vuelta atrás. Un valor por omisión puede dejar una
             * función apagada; no puede destruir datos de nadie. Quien quiera la limpieza la
             * enciende, y entonces sabe lo que va a pasar.
             */
            $table->unsignedSmallInteger('retention_days')->default(0)->after('group_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            $table->dropColumn(['group_seconds', 'retention_days']);
        });
    }
};
