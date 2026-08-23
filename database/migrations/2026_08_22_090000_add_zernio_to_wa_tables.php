<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos vías por las que un negocio puede tener WhatsApp.
 *
 * `evolution` empareja por código QR: dos minutos, cualquier número, gratis. Por debajo usa la sesión
 * de WhatsApp Web, que NO es la API oficial, y Meta puede bloquear el número si detecta automatización
 * agresiva. Además vive en un contenedor propio, así que en producción —Vercel— no hay nada que
 * alcanzar salvo que alguien tenga una máquina encendida todo el día detrás de un túnel.
 *
 * `zernio` es la API oficial de Meta a través del proveedor que ya se usa para Instagram. No hay
 * riesgo de bloqueo, funciona desde Vercel sin infraestructura propia y, para este bot, no cuesta
 * dinero: Meta no cobra los mensajes de servicio dentro de la ventana de 24 h, y el bot solo contesta
 * a quien acaba de escribir. A cambio no hay QR —el alta es por Meta Business— y el número no puede
 * estar activo en la app de WhatsApp.
 *
 * Se guardan las dos porque ninguna sirve para todos: un colmado que no va a pasar la verificación de
 * Meta solo puede usar el QR, y una empresa que factura por WhatsApp no debería arriesgar su número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            // Por omisión la del QR: es la que ya funcionaba y la que no exige trámites con Meta.
            $table->string('provider', 20)->default('evolution')->after('company_id');
        });

        Schema::table('wa_conversations', function (Blueprint $table): void {
            /*
             * Por Zernio no se contesta a un teléfono, se contesta a una CONVERSACIÓN.
             *
             * La bandeja unificada envía con `POST /v1/inbox/conversations/{id}/messages`, y necesita
             * además de qué cuenta sale. Los dos valores llegan en el aviso entrante y se guardan
             * aquí, porque cuando toque responder ya no habrá aviso donde mirarlos.
             *
             * Y de aquí sale una limitación que hay que enseñar, no esconder: sin conversación previa
             * no se puede escribir el primero. Es la regla de Meta —la ventana la abre el cliente—,
             * no una carencia del proveedor.
             */
            $table->string('external_conversation_id')->nullable()->after('phone');
            $table->string('external_account_id')->nullable()->after('external_conversation_id');

            // Se busca por él al llegar cada mensaje: sin índice, cada aviso recorrería la tabla.
            $table->index(['company_id', 'external_conversation_id'], 'wa_conv_externa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wa_bot_settings', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });

        Schema::table('wa_conversations', function (Blueprint $table): void {
            $table->dropIndex('wa_conv_externa_idx');
            $table->dropColumn(['external_conversation_id', 'external_account_id']);
        });
    }
};
