<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Services\ZernioWebhookRegistrar;
use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use App\Modules\WhatsApp\Http\Requests\SendWhatsAppMessageRequest;
use App\Modules\WhatsApp\Http\Requests\StoreBotRequest;
use App\Modules\WhatsApp\Models\WaBotSetting;
use App\Modules\WhatsApp\Models\WaConversation;
use App\Modules\WhatsApp\Models\WaTemplate;
use App\Modules\WhatsApp\Services\WhatsAppService;
use App\Modules\WhatsApp\Support\InboxPresenter;
use App\Modules\WhatsApp\Support\LineStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Bandeja de entrada de WhatsApp: envío de mensajes y emparejamiento de la línea.
 * La lógica de negocio vive en WhatsAppService y en el gateway (Evolution API).
 */
final class WhatsAppController extends Controller
{
    public function send(SendWhatsAppMessageRequest $request, WhatsAppService $whatsApp): RedirectResponse
    {
        $data = $request->validated();

        try {
            $whatsApp->sendText($data['phone'], $data['body']);
        } catch (Throwable $e) {
            // El mensaje ya quedó registrado como "fallido"; se informa sin filtrar detalles internos.
            report($e);

            return back()
                ->withInput()
                ->with('panel_error', 'No se pudo enviar el mensaje. Revisa el estado de la línea de WhatsApp.');
        }

        return redirect()
            ->route('panel.whatsapp', ['c' => $data['phone']])
            ->with('panel_ok', 'Mensaje enviado.');
    }

    /**
     * Estado de la bandeja para el sondeo del navegador: mensajes entrantes que llegan por el
     * webhook y transiciones de estado de los salientes (Pendiente → Enviado) sin recargar.
     */
    public function poll(Request $request, InboxPresenter $inbox): JsonResponse
    {
        // Las consultas ya están aisladas por empresa (CompanyScope).
        return response()->json($inbox->payload((string) $request->query('c', '')));
    }

    /**
     * Inicia el emparejamiento con WhatsApp y devuelve el QR a escanear.
     */
    public function connect(WhatsAppConnection $connection): RedirectResponse
    {
        try {
            $result = $connection->connect();
        } catch (Throwable $e) {
            report($e);

            return back()->with('panel_error', 'No se pudo contactar con Evolution API. Verifica que el servicio esté activo.');
        }

        if ($result['state'] === 'log') {
            /*
             * Aquí hay DOS causas muy distintas y antes se contaban como una sola.
             *
             * Decir «no está configurado en este servidor» a una empresa que eligió la vía oficial y
             * no tiene clave de Zernio es mentirle: el servidor está bien, lo que falta es su clave,
             * y además la puede poner ella misma en Redes sociales. Mandarla a mirar el servidor es
             * mandarla a donde no está el problema.
             */
            $ajustes = WaBotSetting::paraEmpresaSiHay();

            return back()->with('panel_error', $ajustes?->usaZernio() === true
                ? 'Para conectar por la vía oficial hace falta tu clave de Zernio. Ponla en Redes sociales y vuelve aquí.'
                : 'El emparejamiento por código QR no está disponible en este servidor. Usa la vía oficial de Meta, que no necesita nada instalado.');
        }

        /*
         * La vía oficial no da un QR, da una dirección: el negocio se va a Meta a elegir su cuenta y
         * su número, y vuelve conectado. Se sale de la aplicación a propósito —es el alta de Meta, no
         * la nuestra— y por eso es una redirección de verdad y no un enlace pintado en la página.
         */
        if (($result['url'] ?? null) !== null) {
            return redirect()->away((string) $result['url']);
        }

        if ($result['qr'] === null) {
            return back()->with('panel_ok', 'La línea ya está conectada.');
        }

        return back()->with('wa_qr', $result['qr']);
    }

    /**
     * Desvincula el teléfono de la línea.
     *
     * La otra mitad de `whatsapp.connect`, que decía «vincula/desvincula» desde el principio y solo
     * sabía hacer lo primero: cambiar de número obligaba a entrar al panel de Evolution.
     *
     * NO borra la instancia, así que el histórico de la conversación sigue donde estaba y volver a
     * vincular es escanear otro QR.
     */
    public function disconnect(WhatsAppConnection $connection, LineStatus $linea): RedirectResponse
    {
        try {
            $ok = $connection->logout();
        } catch (Throwable $e) {
            report($e);

            return back()->with('panel_error', 'No se pudo contactar con Evolution API para desvincular la línea.');
        }

        // Que la pantalla no siga diciendo «En línea» diez segundos después de desvincular.
        $linea->olvidar();

        return $ok
            ? back()->with('panel_ok', 'Línea desvinculada. Escanea un QR nuevo cuando quieras volver a conectarla.')
            : back()->with('panel_error', 'La línea no se pudo desvincular. Puede que ya estuviera desconectada.');
    }

    /**
     * Guarda lo que el bot sabe del negocio.
     */
    public function saveBot(StoreBotRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        abort_if($companyId === null, 403);

        if (! DbTable::existe('wa_bot_settings')) {
            return back()->with('panel_error', 'El asistente todavía no está disponible en este servidor.');
        }

        $ajustes = WaBotSetting::paraEmpresa($companyId);

        $ajustes->fill([
            'provider' => $request->input('provider', $ajustes->provider),
            'is_active' => $request->boolean('is_active'),
            'business_info' => $request->input('business_info'),
            'greeting' => $request->input('greeting'),
            'instructions' => $request->input('instructions'),
            'uses_documents' => $request->boolean('uses_documents'),
            'includes_plans' => $request->boolean('includes_plans'),
        ])->save();

        /*
         * Los dos ajustes nuevos van APARTE y solo si sus columnas existen.
         *
         * Aquí las migraciones se aplican a mano y el despliegue no las corre: entre que sale el
         * código y alguien migra, meterlos en el fill() de arriba haría que guardar la pantalla
         * entera fallara. Perder el texto del negocio por una columna que todavía no está sería un
         * intercambio pésimo, y le pasaría a todo el que abriera esta pantalla en ese hueco.
         */
        if (DbTable::tieneColumna('wa_bot_settings', 'group_seconds')) {
            $ajustes->forceFill([
                'group_seconds' => (int) $request->input('group_seconds', 8),
                'retention_days' => (int) $request->input('retention_days', 0),
            ])->save();
        }

        /*
         * Por la vía oficial los mensajes entran por el webhook de Zernio, así que hay que darlo de
         * alta —o de baja— según quede el interruptor.
         *
         * No se deja que un fallo aquí tumbe el guardado: lo que el dueño escribió ya está a salvo, y
         * un webhook que no se pudo registrar se arregla volviendo a guardar. Perder el texto del
         * negocio porque Zernio no contestó sería mucho peor.
         */
        $avisoWebhook = null;

        if ($ajustes->usaZernio()) {
            try {
                $company = $currentCompany->model();

                if ($company !== null && ! (new ZernioWebhookRegistrar($company))->sincronizar() && $ajustes->is_active) {
                    $avisoWebhook = ' Ojo: no se pudo activar la recepción de mensajes en Zernio. Vuelve a guardar en un momento.';
                }
            } catch (Throwable $e) {
                report($e);
                $avisoWebhook = ' Ojo: no se pudo contactar con Zernio para activar la recepción de mensajes.';
            }
        }

        return back()->with('panel_ok', ($ajustes->puedeContestar()
            ? 'El asistente está encendido y ya atiende a tus clientes.'
            : 'Guardado. El asistente está apagado: los mensajes los contestas tú.').$avisoWebhook);
    }

    /**
     * Devuelve una conversación al bot.
     *
     * El bot se aparta solo cuando el cliente pide una persona o cuando no sabe, y NO vuelve por su
     * cuenta: si volviera, se metería en medio de la conversación que una persona está teniendo. Que
     * vuelva es una decisión de quien está atendiendo, y por eso es un botón.
     */
    public function resumeBot(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $phone = (string) $request->input('phone', '');

        $conversacion = WaConversation::query()->where('phone', $phone)->first();

        if ($conversacion === null) {
            return back()->with('panel_error', 'No encontramos esa conversación.');
        }

        $conversacion->forceFill(['bot_paused_at' => null])->save();

        return redirect()
            ->route('panel.whatsapp', ['c' => $phone])
            ->with('panel_ok', 'El asistente vuelve a contestar en esta conversación.');
    }

    /**
     * Guarda una respuesta rápida.
     *
     * `WaTemplate` estaba en el proyecto desde el principio —modelo, tabla y hasta su permiso— y no
     * la usaba nadie: ni pantalla, ni ruta, ni prueba. Es lo que más se repite escribiendo a mano en
     * una bandeja («¿Me pasas tu dirección?», «Cerramos a las 8»), así que aquí sí gana su sitio.
     */
    public function storeTemplate(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:1000'],
        ], [
            'name.required' => 'Ponle un nombre para reconocerla.',
            'body.required' => 'Escribe el texto de la respuesta.',
        ]);

        WaTemplate::create($datos + ['is_active' => true]);

        return back()->with('panel_ok', 'Respuesta rápida guardada.');
    }

    public function destroyTemplate(WaTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('panel_ok', 'Respuesta rápida borrada.');
    }
}
