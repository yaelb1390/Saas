<?php

declare(strict_types=1);

namespace App\Modules\Core\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Código de 6 dígitos para crear una contraseña nueva.
 *
 * A diferencia de los otros correos del sistema, este NO se encola: quien acaba de pedirlo está
 * mirando la pantalla, esperando el código para teclearlo. Encolarlo lo dejaría además a merced de
 * que haya un proceso que vacíe la cola, y en producción no lo hay.
 *
 * Reemplaza a `PasswordResetMail` (enlace clicable, ya retirado): ver config/fortify.php,
 * `resetPasswords()` queda apagado.
 */
final class PasswordResetCodeMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $code,
        public readonly int $expiresInMinutes,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu código para recuperar la contraseña de BM Business OS');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-code',
            text: 'emails.password-reset-code-text',
        );
    }
}
