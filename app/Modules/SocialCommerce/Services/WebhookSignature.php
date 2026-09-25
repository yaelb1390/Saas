<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

/**
 * Verifica la firma del webhook propio de Social Commerce.
 *
 * HMAC-SHA256 del cuerpo crudo, mismo esquema que ya usa
 * {@see \App\Modules\Social\Http\Controllers\ZernioWebhookController::comprobarFirma()} — es
 * Zernio quien firma en los dos casos, así que es el único esquema real que hay que verificar.
 *
 * A propósito NO lleva la ventana de frescura (sello de tiempo) que sí tiene
 * {@see \App\Modules\Core\Support\PolarSignature}: Polar manda una cabecera `webhook-timestamp`
 * propia y Zernio no manda ninguna — no hay nada que comprobar sin inventar un dato que la API no
 * ofrece. La protección contra reintentos duplicados la da la idempotencia de
 * `social_commerce_webhook_events`, no la firma.
 */
final class WebhookSignature
{
    public function __construct(private readonly string $secret) {}

    public function isConfigured(): bool
    {
        return $this->secret !== '';
    }

    public function verify(string $header, string $rawPayload): bool
    {
        if (! $this->isConfigured() || $header === '') {
            return false;
        }

        $esperada = hash_hmac('sha256', $rawPayload, $this->secret);
        $recibida = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;

        return hash_equals($esperada, $recibida);
    }
}
