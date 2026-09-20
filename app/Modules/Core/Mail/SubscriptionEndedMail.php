<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use App\Modules\Core\Support\PersonName;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Lo que recibe el cliente cuando su suscripción TERMINA y se le retira el acceso.
 *
 * Reemplaza el aviso de Polar («Your subscription has ended»). Hasta ahora la aplicación retiraba el
 * acceso sin decir nada: el cliente entraba un día y se encontraba con la cuenta bloqueada.
 *
 * Dice cosas distintas según `$requestedByCustomer`: si canceló él, es el final que pidió; si no, suele
 * ser un cobro que no se pudo hacer, y darlo por «como pediste» sería mentirle. En los dos casos lo
 * importante es lo mismo: sus datos no se borran y puede volver cuando quiera.
 */
final class SubscriptionEndedMail extends Mailable
{
    public readonly string $firstName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly bool $requestedByCustomer,
        public readonly string $resubscribeUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {
        $this->firstName = PersonName::first($ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu suscripción ha terminado · Tus datos siguen guardados · BM Business OS',
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-ended',
            text: 'emails.subscription-ended-text',
        );
    }
}
