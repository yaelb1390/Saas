<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Lo que recibe el cliente cuando cancela su suscripción.
 *
 * Existe porque Polar manda su propio aviso —«Your subscription was canceled»— en inglés, con su
 * marca y sin decir nada útil. Este es el de la empresa: en español, con el nombre de quien cancela,
 * hasta cuándo conserva el acceso, que sus datos no se borran y cómo arrepentirse.
 *
 * NO implementa `ShouldQueue` a propósito. En producción esto corre en Vercel, que no tiene worker de
 * colas: un correo encolado no lo recogería nadie y el cliente no recibiría nada, sin ningún error
 * visible. Sin cola, `send()` lo manda en el acto.
 */
final class SubscriptionCancelledMail extends Mailable
{
    /** Solo el nombre de pila: «Hola, Yael» y no «Hola, Yael Berroa Pérez». */
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
        $this->firstName = trim(strtok(trim($ownerName), ' ') ?: $ownerName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Cancelaste tu suscripción · Sigues con acceso hasta el {$this->accessUntil->format('d/m/Y')} · BM Business OS",
            // El correo invita a contar por qué se cancela y a responder. Sin esto la respuesta iría a
            // la dirección de envío, que nadie lee.
            replyTo: filled($this->supportEmail) ? [new Address($this->supportEmail)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-cancelled',
            text: 'emails.subscription-cancelled-text',
        );
    }
}
