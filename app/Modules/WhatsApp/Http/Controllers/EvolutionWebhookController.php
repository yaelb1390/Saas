<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Recibe los webhooks entrantes de Evolution API (mensajes de WhatsApp).
 *
 * Seguridad: valida un secreto compartido y resuelve la empresa (tenant) por el nombre de la
 * instancia, que debe coincidir con el slug de la empresa. Nunca confía en un company_id del
 * cuerpo de la petición.
 */
final class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, WhatsAppService $whatsApp, CurrentCompany $currentCompany): JsonResponse
    {
        $secret = (string) config('evolution.webhook_secret');
        $provided = (string) ($request->header('apikey') ?? $request->query('secret', ''));

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            abort(401, 'Webhook no autorizado.');
        }

        $company = Company::where('slug', (string) $request->input('instance'))->first();

        if ($company === null) {
            return response()->json(['ignored' => true], 202);
        }

        $currentCompany->set($company->id);

        $data = (array) $request->input('data', []);
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
