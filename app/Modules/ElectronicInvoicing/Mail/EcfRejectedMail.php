<?php

declare(strict_types=1);

namespace App\Modules\ElectronicInvoicing\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * La DGII rechazó un e-CF de producción: ese comprobante no tiene validez y hay que emitir otro
 * corregido. Va al dueño de la empresa con el motivo y el enlace al documento.
 */
final class EcfRejectedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $companyName,
        public readonly string $encf,
        public readonly string $typeLabel,
        public readonly string $total,
        public readonly string $reason,
        public readonly string $documentUrl,
        public readonly string $supportWhatsapp,
        public readonly string $supportEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "La DGII rechazó el comprobante {$this->encf} · BM Business OS");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ecf-rejected', text: 'emails.ecf-rejected-text');
    }
}
