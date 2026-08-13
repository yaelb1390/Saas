<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Enlace para crear una contraseña nueva.
 *
 * A diferencia de los otros correos del sistema, este NO se encola: quien acaba de pedirlo está
 * mirando la pantalla, esperando. Un enlace que llega diez minutos tarde ya no sirve, y encolarlo lo
 * dejaría además a merced de que haya un proceso que vacíe la cola.
 *
 * Sustituye a la notificación que trae Laravel, que llega en inglés y con otra plantilla.
 */
final class PasswordResetMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $resetUrl,
        public readonly int $expiresInMinutes,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        // El remitente sale de config('mail.from'); no hace falta fijarlo aquí.
        return new Envelope(subject: 'Recupera tu contraseña de BM Business OS');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset',
            text: 'emails.password-reset-text',
        );
    }
}
