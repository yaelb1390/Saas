<?php

declare(strict_types=1);

namespace App\Modules\Social\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Social\Models\SocialWelcome;
use App\Modules\Social\Models\SocialWelcomeSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contesta con una bienvenida a quien escribe por primera vez.
 *
 * QUÉ NO ES: no saluda a quien te sigue. Instagram no lo permite —seguir no abre la ventana de
 * mensajería; la abre la persona al escribir, comentar o responder una historia— y la API de Zernio
 * no tiene siquiera un evento de «nuevo seguidor». Esto responde a quien YA escribió, que es cuando
 * se puede y, de paso, cuando de verdad viene a cuento.
 *
 * Todas las decisiones están aquí y no en el controlador para que se puedan probar sin fabricar una
 * petición firmada, y para que solo haya un sitio donde mirar cuando algo no salga.
 */
final class WelcomeMessenger
{
    /** Solo estas. El aviso de Zernio es común a nueve plataformas. */
    private const REDES = ['instagram', 'facebook'];

    /**
     * @param  array<string, mixed>  $aviso  el cuerpo del webhook, tal cual
     * @return string qué se hizo, para poder verlo en el registro
     */
    public function atender(Company $empresa, array $aviso): string
    {
        $ajustes = SocialWelcomeSetting::paraEmpresa((int) $empresa->id);

        if (! $ajustes->is_active) {
            return 'apagada';
        }

        $conversacion = (string) data_get($aviso, 'conversation.id', '');
        $plataforma = (string) data_get($aviso, 'conversation.platform', '');
        $cuenta = (string) data_get($aviso, 'account.id', data_get($aviso, 'account._id', ''));

        if ($conversacion === '' || $cuenta === '') {
            return 'aviso incompleto';
        }

        /*
         * WhatsApp entra por el mismo webhook y NO se toca: ese módulo tiene su propia bandeja y su
         * propio flujo, y dos sistemas contestando el mismo mensaje es peor que ninguno.
         */
        if (! in_array($plataforma, self::REDES, true)) {
            return 'otra red: '.$plataforma;
        }

        /*
         * Un mensaje NUESTRO no se saluda a sí mismo.
         *
         * `message.received` es entrante por definición, pero el aviso trae la dirección y
         * comprobarlo cuesta una línea. Si algún día Zernio ampliara lo que manda por aquí, esto
         * evita que le escribamos a un cliente por haberle escrito nosotros.
         */
        if (data_get($aviso, 'message.isFromMe') === true || data_get($aviso, 'message.direction') === 'outbound') {
            return 'saliente';
        }

        /*
         * La marca va ANTES de enviar, y ahí está toda la defensa.
         *
         * La unicidad de (empresa, conversación) es lo que hace que un cliente que escribe tres
         * mensajes seguidos —o un reintento de Zernio, que reintenta— no reciba tres bienvenidas.
         * Si la fila ya existía, la base lo rechaza y aquí no se manda nada.
         *
         * Marcar después de enviar tendría el fallo clásico: dos avisos a la vez pasarían los dos la
         * comprobación y saldrían dos mensajes. Y dos mensajes idénticos seguidos de un negocio es
         * exactamente lo que Instagram penaliza.
         */
        try {
            SocialWelcome::create([
                'company_id' => $empresa->id,
                'conversation_id' => $conversacion,
                'participant_name' => data_get($aviso, 'conversation.participantName'),
                'platform' => $plataforma,
            ]);
        } catch (QueryException) {
            return 'ya saludado';
        }

        try {
            (new ZernioClient($empresa))->enviarMensaje(
                $conversacion,
                $cuenta,
                $ajustes->textoParaEnviar(),
                // La misma conversación siempre da la misma clave: si esto se reintentara, Zernio
                // reconocería el envío en vez de repetirlo.
                'bienvenida-'.$empresa->id.'-'.sha1($conversacion),
            );
        } catch (Throwable $e) {
            /*
             * Si el envío falla, la marca se retira: si no, esa persona no recibiría la bienvenida
             * NUNCA, ni cuando el servicio vuelva. Se prefiere arriesgarse a un duplicado —que la
             * clave de idempotencia de Zernio todavía puede atrapar— antes que perderla en silencio.
             */
            SocialWelcome::query()
                ->where('company_id', $empresa->id)
                ->where('conversation_id', $conversacion)
                ->delete();

            SystemEvent::registrar(
                type: 'integration.failed',
                message: 'La bienvenida no se pudo enviar',
                contexto: ['motivo' => $e->getMessage()],
                level: SystemEvent::AVISO,
                companyId: (int) $empresa->id,
            );

            Log::warning('bienvenida: no se pudo enviar', [
                'company_id' => $empresa->id,
                'motivo' => $e->getMessage(),
            ]);

            return 'falló el envío';
        }

        $ajustes->increment('sent_count');
        $ajustes->forceFill(['last_sent_at' => now()])->save();

        return 'enviada';
    }
}
