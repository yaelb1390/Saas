<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Models\SocialWelcomeSetting;
use App\Modules\Social\Services\WelcomeMessenger;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Recibe los avisos de Zernio: mensajes entrantes de Instagram, Facebook y WhatsApp.
 *
 * Un solo webhook y DOS destinos, que es lo que hay que tener claro al leer esto: Zernio manda
 * `message.received` para todas las plataformas por la misma dirección, así que aquí se reparte. Los
 * de Instagram y Facebook van a la bienvenida; los de WhatsApp entran en la bandeja de ese módulo,
 * que tiene su propio bot. Que los dos contestaran el mismo mensaje sería peor que ninguno.
 *
 * Dos cierres independientes, como el webhook de WhatsApp:
 *
 *  1. La EMPRESA sale de un token opaco en la dirección, nunca de nada que venga en el cuerpo. Quien
 *     mande un aviso no puede elegir en nombre de qué empresa se actúa.
 *  2. La FIRMA (HMAC-SHA256 del cuerpo crudo) demuestra que el aviso lo mandó Zernio. Sin ella,
 *     bastaría con conocer la dirección para hacer que le escribamos a quien sea desde la cuenta de
 *     Instagram de un cliente.
 */
final class ZernioWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        WelcomeMessenger $bienvenida,
        CurrentCompany $actual,
        WhatsAppService $whatsapp,
    ): JsonResponse {
        $ajustes = SocialWelcomeSetting::withoutGlobalScopes()
            ->with('company')
            ->where('token', $token)
            ->first();

        // Mismo 401 que con la firma mala, y a propósito: distinguir «token que no existe» de
        // «firma incorrecta» le diría a quien prueba direcciones cuándo ha acertado con una.
        if ($ajustes === null || $ajustes->company === null) {
            SystemEvent::registrar(
                type: 'webhook.rejected',
                message: 'Aviso de redes rechazado: la dirección no corresponde a ninguna empresa',
                level: SystemEvent::AVISO,
            );

            abort(401, 'Aviso no autorizado.');
        }

        $this->comprobarFirma($request, (string) $ajustes->secret);

        $actual->set((int) $ajustes->company_id);

        $aviso = (array) $request->json()->all();

        try {
            $resultado = $this->esDeWhatsApp($aviso)
                ? $this->wasap($aviso, $whatsapp)
                : $bienvenida->atender($ajustes->company, $aviso);
        } catch (Throwable $e) {
            /*
             * Un fallo nuestro NO puede devolver 500.
             *
             * Zernio reintenta lo que falla, así que un error que se repita convertiría un problema
             * en un bucle: el mismo aviso llegando una y otra vez, cada vez intentando escribir. Se
             * deja constancia por el registro de errores —al que ya llega todo lo reportado— y se
             * contesta que quedó recibido.
             */
            report($e);

            return response()->json(['ok' => true, 'resultado' => 'error'], 200);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    /**
     * ¿Este aviso es de WhatsApp?
     *
     * Se mira `message.platform` primero porque es el campo que la especificación declara obligatorio
     * en el aviso; `conversation.platform` va de reserva por si llegara un aviso con la forma vieja.
     *
     * @param  array<string, mixed>  $aviso
     */
    private function esDeWhatsApp(array $aviso): bool
    {
        $plataforma = data_get($aviso, 'message.platform', data_get($aviso, 'conversation.platform'));

        return $plataforma === 'whatsapp';
    }

    /**
     * Un mensaje de WhatsApp entra en la bandeja del módulo, que despierta al bot.
     *
     * @param  array<string, mixed>  $aviso
     */
    private function wasap(array $aviso, WhatsAppService $whatsapp): string
    {
        $conversacion = (string) data_get($aviso, 'message.conversationId', data_get($aviso, 'conversation.id', ''));
        $cuenta = (string) data_get($aviso, 'account.id', data_get($aviso, 'account._id', ''));
        $texto = data_get($aviso, 'message.text');

        if ($conversacion === '' || $cuenta === '') {
            return 'aviso incompleto';
        }

        /*
         * Solo texto.
         *
         * Una foto o un audio llegan con `text` vacío y los adjuntos aparte. El bot no sabría qué
         * hacer con ellos y guardarlos en blanco llenaría la bandeja de mensajes vacíos, así que se
         * ignoran en silencio hasta que se decida cómo atenderlos. Es la misma regla que ya sigue el
         * webhook de Evolution.
         */
        if (! is_string($texto) || trim($texto) === '') {
            return 'sin texto';
        }

        /*
         * `direction` viene como `incoming`/`outgoing`, no `inbound`/`outbound`.
         *
         * `message.received` es entrante por definición, pero comprobarlo cuesta una línea y evita
         * que, si Zernio ampliara lo que manda por aquí, el bot le conteste a un mensaje nuestro.
         */
        if (data_get($aviso, 'message.direction') === 'outgoing') {
            return 'saliente';
        }

        /*
         * La identidad de quien escribe.
         *
         * `sender.id` es el teléfono sin el «+» cuando lo hay, y el identificador propio de Meta
         * (BSUID) cuando no: desde abril de 2026 se puede escribir a un negocio con nombre de usuario
         * y sin enseñar el número. `phoneNumber` viene en E.164 y por eso se le quita el «+», para que
         * case con el formato que ya usa el resto del módulo.
         */
        $identidad = (string) data_get($aviso, 'message.sender.id', '');
        $telefono = data_get($aviso, 'message.sender.phoneNumber');
        $telefono = is_string($telefono) && $telefono !== '' ? ltrim($telefono, '+') : null;

        if ($identidad === '' && $telefono === null) {
            return 'sin remitente';
        }

        $whatsapp->recordInboundFromZernio(
            conversationId: $conversacion,
            accountId: $cuenta,
            identidad: $identidad !== '' ? $identidad : (string) $telefono,
            body: $texto,
            phone: $telefono,
            externalId: data_get($aviso, 'message.platformMessageId'),
            name: data_get($aviso, 'message.sender.name'),
        );

        return 'recibido';
    }

    /**
     * La firma va sobre el cuerpo CRUDO, no sobre el array decodificado.
     *
     * Volver a serializar el JSON cambiaría espacios y orden de claves, y el HMAC dejaría de cuadrar
     * por motivos que no tienen nada que ver con la seguridad.
     */
    private function comprobarFirma(Request $request, string $secreto): void
    {
        $firma = (string) $request->header('X-Zernio-Signature', '');

        abort_if($firma === '' || $secreto === '', 401, 'Aviso sin firma.');

        $esperada = hash_hmac('sha256', $request->getContent(), $secreto);

        // Algunas pasarelas prefijan el algoritmo. Se aceptan las dos formas y se comparan en tiempo
        // constante: un `===` filtraría por cuánto tarda en fallar.
        $limpia = str_starts_with($firma, 'sha256=') ? substr($firma, 7) : $firma;

        abort_unless(hash_equals($esperada, $limpia), 401, 'Firma incorrecta.');
    }
}
