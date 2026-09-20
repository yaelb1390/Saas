<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use App\Modules\Core\Support\PersonName;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Lo que recibe el cliente cuando no se pudo cobrar la renovación de su suscripción.
 *
 * Reemplaza el aviso de Polar («Payment failed»), que llega en inglés y con su marca. Es el correo que más
 * hace falta que se entienda y se abra a tiempo: quien no lo lee pierde el acceso sin saber por qué.
 *
 * `$updateCardUrl` apunta a una ruta NUESTRA, no al portal de Polar: el enlace de una sesión del portal
 * caduca en una hora y este correo se puede abrir días después. La ruta crea la sesión en el momento del
 * clic. Sin cola a propósito, igual que los demás correos de suscripción: en Vercel no hay worker.
 */
final class SubscriptionPaymentFailedMail extends Mailable
{
    public readonly string $firstName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly string $planPrice,
        public readonly string $billingCycleLabel,
        public readonly string $updateCardUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {
        $this->firstName = PersonName::first($ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'No pudimos cobrar tu suscripción · Actualiza tu tarjeta · BM Business OS',
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-payment-failed',
            text: 'emails.subscription-payment-failed-text',
        );
    }
}
