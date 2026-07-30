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
 * Aviso de vencimiento: se envía al dueño cuando su suscripción de pago está por vencer (dentro del
 * umbral del plan). El cobro es manual, así que invita a contactar para renovar.
 */
final class SubscriptionExpiringMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $planName,
        public readonly Carbon $renewsAt,
        public readonly int $daysLeft,
        public readonly string $loginUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        $cuando = $this->daysLeft <= 0
            ? 'vence hoy'
            : "vence en {$this->daysLeft} ".($this->daysLeft === 1 ? 'día' : 'días');

        return new Envelope(
            subject: "Tu suscripción {$cuando} · BM Business OS",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiring',
            text: 'emails.subscription-expiring-text',
        );
    }
}
