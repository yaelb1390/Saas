<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Services\MailTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Correos de prueba del operador de la plataforma.
 *
 * Manda a mano, con datos de ejemplo, los correos que reciben los clientes, para comprobar que llegan
 * a la bandeja de entrada de Gmail, Hotmail, etc. Solo lo atraviesa el super administrador
 * (`platform.manage`), y aun así va con `throttle`: es una puerta para mandar correo con NUESTRO
 * remitente a cualquier dirección.
 */
final class MailTestController extends Controller
{
    public function index(): View
    {
        $mailer = (string) config('mail.default');

        return view('panel.admin.mail-test', [
            'plantillas' => MailTestService::templates(),
            // Con qué se va a enviar de verdad: es lo primero que hay que mirar cuando «no llega nada».
            // Con `log` o `array` el correo NO sale de la aplicación, y ninguna prueba llegaría jamás.
            'mailer' => $mailer,
            'servidor' => $mailer === 'smtp'
                ? config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port')
                : null,
            'remitente' => (string) config('mail.from.address'),
            'nombreRemitente' => (string) config('mail.from.name'),
            'soporte' => (string) config('platform.support_email'),
        ]);
    }

    public function send(Request $request, MailTestService $mails): RedirectResponse
    {
        $datos = $request->validate([
            'plantilla' => ['required', Rule::in(array_keys(MailTestService::templates()))],
            'destino' => ['required', 'email:rfc', 'max:190'],
        ], [
            'plantilla.required' => 'Elige qué correo quieres probar.',
            'plantilla.in' => 'Ese correo no existe.',
            'destino.required' => 'Escribe el correo al que quieres mandar la prueba.',
            'destino.email' => 'Ese correo no parece válido.',
        ]);

        try {
            $mails->send(
                template: $datos['plantilla'],
                to: $datos['destino'],
                replyToSupport: ! $request->boolean('sin_reply_to'),
                operatorName: (string) $request->user()?->name,
                userId: $request->user()?->id,
            );
        } catch (Throwable $e) {
            // El motivo se ENSEÑA: para eso está la herramienta. Un «no se pudo» a secas no diría si es
            // la clave, el remitente sin verificar o el servidor caído.
            return back()->withInput()->with('panel_error', 'No se pudo enviar: '.$this->motivo($e));
        }

        return back()->withInput()->with(
            'panel_ok',
            "Correo de prueba enviado a {$datos['destino']}. El servidor de correo lo aceptó; que llegue a la "
            .'bandeja es otra cosa: mira Brevo → Transactional → Logs y revisa también Correo no deseado.',
        );
    }

    /**
     * El motivo del fallo, apto para pantalla.
     *
     * Los errores del SMTP suelen incluir el usuario con el que se intentó entrar («…with username
     * "abc@smtp-brevo.com"…»). No es una clave, pero tampoco hay por qué dejarlo escrito en pantalla.
     */
    private function motivo(Throwable $e): string
    {
        $texto = preg_replace('/(username|user)\s+"[^"]*"/i', '$1 "***"', $e->getMessage()) ?? '';

        return class_basename($e).': '.mb_substr($texto, 0, 300);
    }
}
