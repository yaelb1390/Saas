<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Lo que recibe el cliente cuando se arrepiente de su baja y la suscripción vuelve a renovarse.
 *
 * Pareja de `SubscriptionCancelledMail`: reemplaza el aviso de Polar («Your subscription is no longer
 * canceled»), que llega en inglés y con su marca. Mismas razones para no encolarlo: ver esa clase.
 */
final class SubscriptionResumedMail extends Mailable
{
    public readonly string $firstName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly string $planPrice,
        public readonly string $billingCycleLabel,
        public readonly Carbon $renewsAt,
        public readonly string $accountUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
        // Ver `SubscriptionCancelledMail`: solo lo apaga la herramienta de correos de prueba.
        public readonly bool $replyToSupport = true,
    ) {
        $this->firstName = trim(strtok(trim($ownerName), ' ') ?: $ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu suscripción sigue activa · BM Business OS',
            replyTo: $this->replyToSupport && filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-resumed',
            text: 'emails.subscription-resumed-text',
        );
    }
}
