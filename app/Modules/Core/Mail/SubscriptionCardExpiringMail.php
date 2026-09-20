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
 * Aviso de que la tarjeta con la que se paga la suscripción vence, y que el cobro puede fallar por eso.
 *
 * Reemplaza el aviso de Polar («Your payment method is about to expire»), que llega en inglés.
 *
 * SUSTITUYE al aviso de renovación en vez de sumarse a él: es el mismo momento (unos días antes del cobro) y
 * dos correos seguidos sobre lo mismo confunden. Por eso repite cuándo y cuánto se cobra. Cuál de los dos sale
 * lo decide el recordatorio diario según la tarjeta (`CardExpiry::atRisk`).
 *
 * `$failsAtRenewal` separa lo grave de lo previsor: la tarjeta ya no servirá el día del cobro (va a fallar)
 * frente a una que aún sirve pero caduca enseguida. El asunto y el tono cambian, porque el primero es un
 * «actúa ya» y el segundo un «conviene cambiarla».
 *
 * Sin cola a propósito, como los demás correos de suscripción: en Vercel no hay worker que la recoja.
 */
final class SubscriptionCardExpiringMail extends Mailable
{
    public readonly string $firstName;

    /** «Visa •••• 4242», o «Tu tarjeta» si Polar no dijo cuál es. */
    public readonly string $cardName;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly string $planPrice,
        public readonly string $billingCycleLabel,
        string $cardBrand,
        string $cardLast4,
        public readonly string $cardExpiry,
        public readonly Carbon $renewsAt,
        public readonly bool $failsAtRenewal,
        public readonly string $accountUrl,
        public readonly string $updateCardUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {
        $this->firstName = PersonName::first($ownerName);

        $name = trim($cardBrand.(filled($cardLast4) ? " •••• {$cardLast4}" : ''));
        $this->cardName = $name !== '' ? $name : 'Tu tarjeta';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->failsAtRenewal
                ? 'Tu tarjeta vence antes de tu próxima renovación · Actualízala · BM Business OS'
                : 'Tu tarjeta vence pronto · Actualízala · BM Business OS',
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-card-expiring',
            text: 'emails.subscription-card-expiring-text',
        );
    }
}
