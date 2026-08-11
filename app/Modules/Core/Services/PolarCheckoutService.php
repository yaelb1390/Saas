<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\Plan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Crea el cobro en Polar y devuelve la dirección a la que mandar al cliente.
 *
 * Aquí solo se ABRE el cobro. Quien activa la suscripción es el webhook, cuando Polar confirma que
 * el dinero entró de verdad. Nunca se activa al volver de la pasarela: la dirección de retorno la
 * puede escribir cualquiera en la barra del navegador, y quien lo hiciera se regalaría el plan.
 */
final class PolarCheckoutService
{
    private const SANDBOX = 'https://sandbox-api.polar.sh';

    private const PRODUCTION = 'https://api.polar.sh';

    public function isConfigured(): bool
    {
        return filled(config('polar.access_token'));
    }

    /**
     * @return string|null La URL de pago, o null si no se pudo abrir el cobro.
     */
    public function createFor(Company $company, Plan $plan, string $email, string $successUrl): ?string
    {
        if (! $this->isConfigured() || ! $plan->isPurchasable()) {
            return null;
        }

        $response = Http::withToken((string) config('polar.access_token'))
            ->acceptJson()
            ->asJson()
            // La barra final importa: sin ella Polar responde una redirección y la petición se pierde.
            ->post($this->baseUrl().'/v1/checkouts/', [
                'products' => [$plan->polar_product_id],
                'success_url' => $successUrl,
                'customer_email' => $email,

                // La pieza clave de toda la integración: con esto el aviso de pago dirá a QUÉ
                // empresa activar, sin depender de adivinar por el correo del comprador.
                'metadata' => [
                    'company_id' => (string) $company->id,
                    'plan_id' => (string) $plan->id,
                ],
            ]);

        if (! $response->successful()) {
            // Un fallo aquí no debe reventar la pantalla: el cliente verá un aviso y podrá
            // escribirnos. Se registra el motivo porque suele ser algo concreto y arreglable
            // (correo con dominio no admitido, producto archivado, token caducado).
            Log::error('Polar: no se pudo abrir el cobro', [
                'empresa' => $company->id,
                'plan' => $plan->slug,
                'estado' => $response->status(),
                'respuesta' => mb_substr($response->body(), 0, 500),
            ]);

            return null;
        }

        $url = $response->json('url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function baseUrl(): string
    {
        return config('polar.server') === 'sandbox' ? self::SANDBOX : self::PRODUCTION;
    }
}
