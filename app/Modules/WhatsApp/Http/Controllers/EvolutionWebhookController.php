<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Services\WhatsAppService;
use App\Modules\WhatsApp\Support\LineStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Recibe los webhooks entrantes de Evolution API.
 *
 * Seguridad: valida un secreto compartido y resuelve la empresa (tenant) por el nombre de la
 * instancia, que debe coincidir con el slug de la empresa. Nunca confía en un company_id del
 * cuerpo de la petición.
 *
 * Atiende DOS eventos y los distingue, que es lo que antes no hacía: llegara lo que llegara, esto
 * lo trataba como un mensaje y le buscaba un teléfono dentro. Con un solo evento suscrito colaba;
 * en cuanto entró `CONNECTION_UPDATE` habría empezado a guardar basura.
 */
final class EvolutionWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsAppService $whatsApp,
        CurrentCompany $currentCompany,
        LineStatus $linea,
    ): JsonResponse {
        $secret = (string) config('evolution.webhook_secret');
        $provided = (string) ($request->header('apikey') ?? $request->query('secret', ''));

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            SystemEvent::registrar(
                type: 'webhook.rejected',
                message: 'Webhook de WhatsApp rechazado: secreto incorrecto',
                contexto: ['instancia' => (string) $request->input('instance', '')],
                level: SystemEvent::AVISO,
            );

            abort(401, 'Webhook no autorizado.');
        }

        $company = Company::where('slug', (string) $request->input('instance'))->first();

        if ($company === null) {
            return response()->json(['ignored' => true], 202);
        }

        $currentCompany->set($company->id);

        $data = (array) $request->input('data', []);

        $evento = $this->evento($request);

        if ($evento === 'connection.update') {
            return $this->conexion($data, $linea);
        }

        /*
         * El `$evento === ''` NO es un descuido.
         *
         * Antes esto no miraba el evento en absoluto: llegara lo que llegara, buscaba un mensaje
         * dentro. Si ahora un aviso sin nombre cayera en el `ignored` de abajo, un mensaje de un
         * cliente se perdería EN SILENCIO —y no habría forma de notarlo hasta que alguien se quejara
         * de que le escribieron y nadie contestó—.
         *
         * Así que, sin nombre, manda la forma: si trae `key`, es un mensaje, como siempre lo fue.
         */
        if ($evento === 'messages.upsert' || ($evento === '' && isset($data['key']))) {
            return $this->mensaje($data, $whatsApp);
        }

        // Cualquier otro evento se acepta y se ignora. Devolver un error haría que Evolution
        // reintentara algo que nunca vamos a saber atender.
        return response()->json(['ignored' => true], 202);
    }

    /**
     * El nombre del evento, en una sola forma.
     *
     * Evolution lo manda en minúsculas con puntos (`messages.upsert`) mientras que al suscribirlo se
     * escribe en mayúsculas con guion bajo (`MESSAGES_UPSERT`). Se normalizan los dos para no depender
     * de cuál de las dos formas use la versión que haya instalada.
     */
    private function evento(Request $request): string
    {
        return str_replace('_', '.', mb_strtolower((string) $request->input('event', '')));
    }

    /**
     * La línea cambió de estado.
     *
     * Esto es lo que hace que la pantalla del QR se entere sola de que ya escaneaste. Antes nadie se
     * lo contaba y la vista tenía que pedir «Recarga esta página».
     *
     * @param  array<string, mixed>  $data
     */
    private function conexion(array $data, LineStatus $linea): JsonResponse
    {
        $estado = (string) ($data['state'] ?? '');

        // Que el próximo sondeo no conteste con lo que había en caché hace diez segundos.
        $linea->olvidar();

        /*
         * Queda en el registro del sistema, y no es decoración: cuando alguien dice «el bot dejó de
         * contestar de repente», lo primero que hay que poder mirar es si la línea se cayó y cuándo.
         *
         * Caerse es AVISO y no GRAVE: un teléfono sin batería tira la sesión, y eso no es una avería
         * de la plataforma.
         */
        SystemEvent::registrar(
            type: 'integration.whatsapp',
            message: match ($estado) {
                'open' => 'La línea de WhatsApp quedó conectada',
                'close' => 'La línea de WhatsApp se desconectó',
                default => 'La línea de WhatsApp está emparejándose',
            },
            contexto: ['estado' => $estado],
            level: $estado === 'close' ? SystemEvent::AVISO : SystemEvent::INFO,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Llegó un mensaje.
     *
     * @param  array<string, mixed>  $data
     */
    private function mensaje(array $data, WhatsAppService $whatsApp): JsonResponse
    {
        $key = (array) ($data['key'] ?? []);

        // Ignora los ecos de mensajes propios (salientes).
        if (($key['fromMe'] ?? false) === true) {
            return response()->json(['ignored' => true], 202);
        }

        $phone = $this->extractPhone((string) ($key['remoteJid'] ?? ''));
        $body = $this->extractBody((array) ($data['message'] ?? []));

        if ($phone !== '' && $body !== null) {
            $whatsApp->recordInbound(
                phone: $phone,
                body: $body,
                externalId: $key['id'] ?? null,
                name: $data['pushName'] ?? null,
            );
        }

        return response()->json(['ok' => true]);
    }

    private function extractPhone(string $remoteJid): string
    {
        return explode('@', $remoteJid)[0];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function extractBody(array $message): ?string
    {
        return $message['conversation']
            ?? ($message['extendedTextMessage']['text'] ?? null);
    }
}
