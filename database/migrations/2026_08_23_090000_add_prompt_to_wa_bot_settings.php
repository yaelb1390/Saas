<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué papel juega el bot, y de dónde más puede sacar lo que dice.
 *
 * Hasta ahora el único campo era `business_info`, que son DATOS —horario, pagos, condiciones—. No
 * había forma de decirle QUIÉN ES: «eres un asesor de BM Business, ofrece una demo, pregunta de qué
 * es el negocio antes de recomendar un plan». Con la instrucción fija escrita para una tienda, un bot
 * que tiene que vender un sistema se comporta como si despachara batidas.
 *
 * Los dos campos van separados a propósito. El papel se reescribe a menudo —se prueba un tono, se
 * cambia la oferta— y los datos casi nunca. Metidos en el mismo cuadro, cada vez que se retoca el
 * tono se corre el riesgo de llevarse por delante el horario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            // El papel. Más corto que los datos a propósito: unas instrucciones que no caben en dos
            // mil caracteres suelen ser datos disfrazados, y esos van en el otro campo o, si son
            // muchos, en la base de conocimiento.
            $table->text('instructions')->nullable()->after('greeting');

            /*
             * Buscar en los documentos de la empresa antes de contestar.
             *
             * Apagado por omisión porque CUESTA: una llamada de embeddings por cada mensaje que
             * entra, dentro de la petición del webhook y encima de la de redacción. Quien lo
             * enciende está aceptando ese gasto a cambio de respuestas con más fondo.
             */
            $table->boolean('uses_documents')->default(false)->after('instructions');

            /*
             * Poder citar los planes de la plataforma y sus precios.
             *
             * Apagado por omisión, y no es una precaución teórica: un colmado contándole a sus
             * clientes los planes de BM Business sería absurdo. Lo enciende quien vende el sistema.
             *
             * Leerlos de la tabla y no copiarlos a mano en el texto es lo que evita que un precio se
             * quede viejo el día que cambie y nadie se acuerde de este campo.
             */
            $table->boolean('includes_plans')->default(false)->after('uses_documents');
        });
    }

    public function down(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            $table->dropColumn(['instructions', 'uses_documents', 'includes_plans']);
        });
    }
};
