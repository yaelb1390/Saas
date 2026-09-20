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
 * Aviso de que la suscripción se va a RENOVAR SOLA, unos días antes del cobro.
 *
 * Es el aviso que corresponde a quien Polar cobra automáticamente. Antes, esa persona recibía el mismo
 * «Tu suscripción está por vencer. Renueva a tiempo» que quien paga a mano, y lo llevaba a escribir a
 * soporte o a intentar pagar algo que se pagaba solo. Aquí no hay nada que renovar: se le dice cuándo se
 * cobra, cuánto, y cómo cancelar o cambiar la tarjeta si no quiere que ocurra.
 *
 * Reemplaza el recordatorio de renovación de Polar, que llega en inglés.
 */
final class SubscriptionRenewalNoticeMail extends Mailable
{
    public readonly string $firstName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly string $planPrice,
        public readonly string $billingCycleLabel,
        public readonly Carbon $renewsAt,
        public readonly int $daysLeft,
        public readonly string $accountUrl,
        public readonly string $updateCardUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {
        $this->firstName = PersonName::first($ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Tu suscripción se renovará el {$this->renewsAt->format('d/m/Y')} · BM Business OS",
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-renewal-notice',
            text: 'emails.subscription-renewal-notice-text',
        );
    }
}
