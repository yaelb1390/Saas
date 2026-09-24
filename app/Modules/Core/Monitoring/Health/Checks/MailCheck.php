<?php

declare(strict_types=1);

namespace App\Modules\Core\Monitoring\Health\Checks;

use App\Modules\Core\Monitoring\Health\HealthCheck;
use App\Modules\Core\Monitoring\Health\HealthResult;
use App\Modules\Core\Support\SecretRedactor;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ¿Responde el servidor SMTP? Solo cuando el correo de verdad sale por SMTP: con el driver `log`
 * —que es lo normal en desarrollo— no hay ningún servidor que preguntar, así que «no aplica» y no
 * «mal», que es justo la distinción que esta fase entera viene a hacer en todos lados.
 *
 * `start()`/`stop()` sobre el transporte de Symfony: abre la conexión, autentica si hace falta, y la
 * cierra. NUNCA se manda un correo de prueba: eso ya lo hace la herramienta de «Correos de prueba»
 * cuando alguien lo pide a propósito, y esto puede correr solo cada cinco minutos.
 */
final class MailCheck implements HealthCheck
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'Correo';
    }

    public function run(): HealthResult
    {
        if ((string) config('mail.default') !== 'smtp') {
            return HealthResult::sinConfigurar('El correo no sale por SMTP en esta instalación');
        }

        $inicio = microtime(true);

        try {
            $transporte = Mail::mailer('smtp')->getSymfonyTransport();
            $transporte->start();
            $transporte->stop();
        } catch (Throwable $e) {
            return HealthResult::caido(SecretRedactor::redact($e->getMessage()));
        }

        $latencia = (int) round((microtime(true) - $inicio) * 1000);

        return HealthResult::sano($latencia);
    }
}
