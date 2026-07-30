<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Confirmación de suscripción/pago: se envía al dueño cada vez que la empresa queda con un plan de
 * pago activo (alta de pago o registro de un pago/renovación). Funciona como recibo del período.
 */
final class SubscriptionConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $moduleLabels
     */
    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly string $planPrice,
        public readonly string $billingCycleLabel,
        public readonly Carbon $renewsAt,
        public readonly array $moduleLabels,
        public readonly string $loginUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Suscripción confirmada · Plan {$this->planName} · BM Business OS",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-confirmed',
            text: 'emails.subscription-confirmed-text',
        );
    }
}
