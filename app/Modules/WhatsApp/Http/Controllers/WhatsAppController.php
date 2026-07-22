<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\WhatsApp\Gateways\WhatsAppConnection;
use App\Modules\WhatsApp\Http\Requests\SendWhatsAppMessageRequest;
use App\Modules\WhatsApp\Services\WhatsAppService;
use App\Modules\WhatsApp\Support\InboxPresenter;
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
            return back()->with('panel_error', 'Evolution API no está configurado (EVOLUTION_BASE_URL). La línea opera en modo registro.');
        }

        if ($result['qr'] === null) {
            return back()->with('panel_ok', 'La línea ya está conectada.');
        }

        return back()->with('wa_qr', $result['qr']);
    }
}
