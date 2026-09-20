<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Mail\SubscriptionCancelledMail;
use App\Modules\Core\Mail\SubscriptionConfirmedMail;
use App\Modules\Core\Mail\SubscriptionEndedMail;
use App\Modules\Core\Mail\SubscriptionPaymentFailedMail;
use App\Modules\Core\Mail\SubscriptionRenewalNoticeMail;
use App\Modules\Core\Mail\SubscriptionResumedMail;
use App\Modules\Core\Models\SystemEvent;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Manda a mano, con datos de ejemplo, los correos que la plataforma envía a los clientes.
 *
 * Existe porque comprobar que un correo LLEGA no se puede hacer desde el código: el servidor de correo
 * lo acepta («Entregado» en Brevo) y aun así el buzón del destinatario —Hotmail sobre todo— puede
 * mandarlo a spam o descartarlo sin decir nada. La única prueba es mandarlo a un buzón de verdad y
 * mirar. Sin esta herramienta, cada prueba exigía provocar una baja o una reactivación reales en
 * Polar, con su suscripción y su cobro de por medio.
 *
 * Usa los MISMOS correos y el MISMO envío que el sistema real, no una copia: si probara otra cosa,
 * un «llegó bien» no diría nada de lo que reciben los clientes.
 */
final class MailTestService
{
    /**
     * Los correos que se pueden probar: clave => [nombre, asunto que verá quien lo reciba].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function templates(): array
    {
        return [
            'baja' => ['Baja de suscripción', '«Cancelaste tu suscripción · Sigues con acceso hasta…»'],
            'reactivacion' => ['Reactivación', '«Tu suscripción sigue activa»'],
            'recibo' => ['Recibo de pago', '«Suscripción confirmada · Plan …»'],
            'aviso_renovacion' => ['Aviso de renovación', '«Tu suscripción se renovará el …»'],
            'pago_fallido' => ['Cobro fallido', '«No pudimos cobrar tu suscripción · Actualiza tu tarjeta»'],
            'terminada' => ['Suscripción terminada', '«Tu suscripción ha terminado · Tus datos siguen guardados»'],
        ];
    }

    /**
     * @param  bool  $replyToSupport  Falso quita la cabecera `Reply-To`, para ver si es lo que hace que un
     *                                buzón rechace el mensaje. Solo afecta a la baja y a la reactivación.
     *
     * @throws InvalidArgumentException si la plantilla no existe.
     * @throws \Throwable cualquier fallo del envío (SMTP caído, credenciales, remitente no verificado):
     *                    NO se traga aquí. Que la herramienta lo enseñe es justo para lo que sirve.
     */
    public function send(string $template, string $to, bool $replyToSupport, string $operatorName, ?int $userId = null): void
    {
        $whatsapp = (string) config('platform.support_whatsapp');
        $support = (string) config('platform.support_email');
        $company = 'Heladería de ejemplo';
        $renews = now()->addDays(30);

        $mail = match ($template) {
            'baja' => new SubscriptionCancelledMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro',
                accessUntil: $renews, daysLeft: 30, accountUrl: route('panel.account'),
                supportWhatsapp: $whatsapp, supportEmail: $support, replyToSupport: $replyToSupport,
            ),
            'reactivacion' => new SubscriptionResumedMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro', planPrice: '1500',
                billingCycleLabel: 'Mensual', renewsAt: $renews, accountUrl: route('panel.account'),
                supportWhatsapp: $whatsapp, supportEmail: $support, replyToSupport: $replyToSupport,
            ),
            'recibo' => new SubscriptionConfirmedMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro', planPrice: '1500',
                billingCycleLabel: 'Mensual', renewsAt: $renews,
                moduleLabels: ['Punto de Venta', 'Inventario', 'Ventas', 'CRM'],
                loginUrl: route('login'), supportWhatsapp: $whatsapp, supportEmail: $support,
            ),
            'aviso_renovacion' => new SubscriptionRenewalNoticeMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro', planPrice: '1500',
                billingCycleLabel: 'Mensual', renewsAt: now()->addDays(5), daysLeft: 5,
                accountUrl: route('panel.account'), updateCardUrl: route('panel.account.portal'),
                supportWhatsapp: $whatsapp, supportEmail: $support,
            ),
            'pago_fallido' => new SubscriptionPaymentFailedMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro', planPrice: '1500',
                billingCycleLabel: 'Mensual', updateCardUrl: route('panel.account.portal'),
                supportWhatsapp: $whatsapp, supportEmail: $support,
            ),
            'terminada' => new SubscriptionEndedMail(
                ownerName: $operatorName, companyName: $company, planName: 'Pro', requestedByCustomer: true,
                resubscribeUrl: route('panel.account'), supportWhatsapp: $whatsapp, supportEmail: $support,
            ),
            default => throw new InvalidArgumentException("Plantilla desconocida: {$template}"),
        };

        // `sendNow` y no `send`: igual que el recibo real. En producción no hay worker de colas, así que
        // un correo encolado no lo recogería nadie; y `sendNow` deja que un fallo del SMTP llegue hasta
        // aquí en vez de perderse en una cola.
        Mail::to($to)->sendNow($mail);

        SystemEvent::registrar(
            type: 'mail.test_sent',
            message: 'Se envió un correo de prueba desde la plataforma',
            contexto: [
                'plantilla' => $template,
                // Solo el dominio: quién hizo la prueba y qué buzón (Gmail, Hotmail…) es lo que importa.
                'dominio_destino' => substr((string) strrchr($to, '@'), 1),
                'con_reply_to' => $replyToSupport,
            ],
            userId: $userId,
        );
    }
}
