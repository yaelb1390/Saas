<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Social\Models\SocialWelcomeSetting;
use App\Modules\Social\Services\WelcomeMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/**
 * Recibe los avisos de Zernio: por ahora, mensajes entrantes de Instagram y Facebook.
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
    public function __invoke(Request $request, string $token, WelcomeMessenger $bienvenida, CurrentCompany $actual): JsonResponse
    {
        $ajustes = SocialWelcomeSetting::withoutGlobalScopes()
            ->with('company')
            ->where('token', $token)
            ->first();

        // Mismo 401 que con la firma mala, y a propósito: distinguir «token que no existe» de
        // «firma incorrecta» le diría a quien prueba direcciones cuándo ha acertado con una.
        abort_if($ajustes === null || $ajustes->company === null, 401, 'Aviso no autorizado.');

        $this->comprobarFirma($request, (string) $ajustes->secret);

        $actual->set((int) $ajustes->company_id);

        try {
            $resultado = $bienvenida->atender($ajustes->company, (array) $request->json()->all());
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
