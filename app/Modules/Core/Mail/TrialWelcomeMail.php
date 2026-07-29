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
 * Correo de bienvenida que recibe quien se registra por su cuenta (prueba gratuita).
 *
 * Se encola (ShouldQueue) para no frenar la respuesta del registro. Recibe datos ya resueltos
 * (strings/fechas, no modelos) para que el job serialice liviano y no dependa de recargar la BD.
 */
final class TrialWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $moduleLabels  Etiquetas de los módulos que eligió probar.
     */
    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly int $trialDays,
        public readonly Carbon $trialEndsAt,
        public readonly Carbon $purgeAt,
        public readonly array $moduleLabels,
        public readonly string $loginUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        // El remitente (from) sale de config('mail.from'); no hace falta fijarlo aquí.
        return new Envelope(
            subject: "¡Bienvenido a BM Business OS! Tu prueba de {$this->trialDays} días está activa",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trial-welcome',
            text: 'emails.trial-welcome-text',
        );
    }
}
