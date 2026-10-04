<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Http\Controllers;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\SystemEvent;
use App\Modules\Core\Support\DbTable;
use App\Modules\ElectronicInvoicing\Models\ElectronicInvoicingSettings;
use App\Modules\ElectronicInvoicing\Receiver\ReceiverService;
use App\Modules\ElectronicInvoicing\Signature\CertificateException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Los servicios PÚBLICOS de la empresa como receptora [DTEE «Creación de Servicios»]: los llama otro
 * contribuyente (o el simulador emisor-receptor de la DGII en pruebas), sin sesión ni CSRF.
 *
 *   https://{host}/api/ecf-receptor/{clave}/fe/autenticacion/api/semilla               GET
 *   https://{host}/api/ecf-receptor/{clave}/fe/autenticacion/api/validacioncertificado POST xml
 *   https://{host}/api/ecf-receptor/{clave}/fe/recepcion/api/ecf                       POST xml
 *   https://{host}/api/ecf-receptor/{clave}/fe/aprobacioncomercial/api/ecf             POST xml
 *
 * Una sola ruta comodín y el recurso se resuelve aquí en minúsculas: el estándar exige que los
 * servicios «no sean sensibles a mayúsculas o minúsculas» y las rutas de Laravel sí lo son.
 * `{clave}` identifica a la empresa sin exponer su id (no se puede recorrer).
 */
final class ReceiverController extends Controller
{
    public function handle(Request $request, string $key, string $path, ReceiverService $receiver): Response
    {
        $company = $this->empresa($key);
        abort_if($company === null, 404);

        $recurso = $this->recurso($path);

        try {
            return match (true) {
                $recurso === 'fe/autenticacion/api/semilla' && $request->isMethod('GET') => response($receiver->seed($company), 200, ['Content-Type' => 'application/xml; charset=utf-8']),
                $recurso === 'fe/autenticacion/api/validacioncertificado' && $request->isMethod('POST') => $this->token($request, $company, $receiver),
                $recurso === 'fe/recepcion/api/ecf' && $request->isMethod('POST') => $this->recepcion($request, $company, $receiver),
                $recurso === 'fe/aprobacioncomercial/api/ecf' && $request->isMethod('POST') => $this->aprobacion($request, $company, $receiver),
                default => response('Recurso no encontrado.', 404),
            };
        } catch (CertificateException $e) {
            // Sin certificado vigente no se puede firmar el acuse de recibo: es un fallo NUESTRO.
            SystemEvent::registrar(type: 'ecf.receiver_unavailable', message: 'Receptor e-CF sin certificado', contexto: ['empresa' => $company->id], level: SystemEvent::GRAVE);

            return response('Servicio de recepción no disponible temporalmente.', 503);
        }
    }

    private function token(Request $request, Company $company, ReceiverService $receiver): Response
    {
        try {
            $t = $receiver->validateSeed($company, $this->xml($request));
        } catch (Throwable $e) {
            return response($e->getMessage(), 400);
        }

        // JSON o XML según lo que pida el consumidor en Accept [DTEE validacioncertificado]. XML solo
        // si lo pide expresamente: el Accept de un navegador también nombra «xml» y espera otra cosa.
        $accept = strtolower((string) $request->header('Accept'));
        if (preg_match('#(application|text)/xml#', $accept) === 1 && ! str_contains($accept, 'json') && ! str_contains($accept, 'text/html')) {
            $xml = '<?xml version="1.0" encoding="utf-8"?><RespuestaAutenticacion><token>'.e($t['token']).'</token><expira>'.$t['expira'].'</expira><expedido>'.$t['expedido'].'</expedido></RespuestaAutenticacion>';

            return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
        }

        return response()->json($t);
    }

    private function recepcion(Request $request, Company $company, ReceiverService $receiver): Response
    {
        if ($denegado = $this->autorizar($request, $company, $receiver)) {
            return $denegado;
        }

        return response($receiver->receiveEcf($company, $this->xml($request), $request->ip()), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function aprobacion(Request $request, Company $company, ReceiverService $receiver): Response
    {
        if ($denegado = $this->autorizar($request, $company, $receiver)) {
            return $denegado;
        }

        $error = $receiver->receiveApproval($company, $this->xml($request));

        // [DTEE] Satisfactorio: HTTP 200 · Insatisfactorio: HTTP 400.
        return $error === null ? response('', 200) : response($error, 400);
    }

    /**
     * Con la autenticación declarada (por omisión, sí: BMIA ofrece los dos recursos), recepción y
     * aprobación exigen el token Bearer obtenido con la semilla.
     */
    private function autorizar(Request $request, Company $company, ReceiverService $receiver): ?Response
    {
        if (! config('ecf.receiver.require_auth', true) || $receiver->tokenIsValid($company, $request->bearerToken())) {
            return null;
        }

        return response('Token no válido o vencido.', 401, ['WWW-Authenticate' => 'Bearer']);
    }

    /** El XML llega como archivo multipart `xml` [DTEE]; se admite también el campo o el cuerpo crudo. */
    private function xml(Request $request): string
    {
        $archivo = $request->file('xml');

        return $archivo !== null ? (string) $archivo->get() : (string) ($request->input('xml') ?? $request->getContent());
    }

    private function empresa(string $key): ?Company
    {
        if (! DbTable::tieneColumna('electronic_invoicing_settings', 'receiver_key') || ! DbTable::existe('electronic_received_documents')) {
            return null;
        }

        $ajustes = ElectronicInvoicingSettings::query()->withoutGlobalScopes()->where('receiver_key', strtolower($key))->first();
        $company = $ajustes !== null ? Company::query()->find($ajustes->company_id) : null;

        return $company?->hasModule('e_invoicing') ? $company : null;
    }

    /** «FE/Recepción/API/ECF» → «fe/recepcion/api/ecf». */
    private function recurso(string $path): string
    {
        $r = mb_strtolower(trim(rawurldecode($path), '/'));

        return strtr($r, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
}
