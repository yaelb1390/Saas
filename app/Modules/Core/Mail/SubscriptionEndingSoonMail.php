<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use App\Modules\Core\Support\PersonName;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Recordatorio, unos días antes del final, a quien YA pidió la baja: su acceso termina y aún puede
 * reactivarla.
 *
 * Antes recibía el mismo «Tu suscripción está por vencer. Renueva a tiempo… escríbenos para renovar» que
 * quien paga a mano. Para quien canceló eso es una confusión: no tiene nada que renovar ni a quién
 * escribir, lo que puede hacer es arrepentirse con un clic desde su panel. Es lo que dice este.
 *
 * Sin cola a propósito, como los demás correos de suscripción: en Vercel no hay worker que la recoja.
 */
final class SubscriptionEndingSoonMail extends Mailable
{
    public readonly string $firstName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly Carbon $accessUntil,
        public readonly int $daysLeft,
        public readonly string $accountUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {
        $this->firstName = PersonName::first($ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu acceso termina el {$this->accessUntil->format('d/m/Y')} · Aún puedes reactivarla · BM Business OS",
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-ending-soon',
            text: 'emails.subscription-ending-soon-text',
        );
    }
}
