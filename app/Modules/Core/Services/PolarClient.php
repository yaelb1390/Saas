<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * La puerta de la app hacia la API de Polar: a dónde llamar y con qué credencial.
 *
 * Existe para que cada servicio que habla con Polar (abrir un cobro, cancelar una suscripción) no
 * repita la dirección de cada entorno ni la forma de autenticarse. Si mañana cambia el token o se
 * añade una cabecera común, se cambia aquí y no en cada servicio.
 */
final class PolarClient
{
    private const SANDBOX = 'https://sandbox-api.polar.sh';

    private const PRODUCTION = 'https://api.polar.sh';

    public function isConfigured(): bool
    {
        return filled(config('polar.access_token'));
    }

    /**
     * Petición ya autenticada. Se usa junto con `url()`.
     */
    public function http(): PendingRequest
    {
        return Http::withToken((string) config('polar.access_token'))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Dirección completa de un recurso de la API.
     *
     * @param  string  $path  Empieza por «/». La barra final importa según el recurso: en
     *                        `/v1/checkouts/` hace falta y sin ella Polar responde una redirección y
     *                        la petición se pierde; en `/v1/subscriptions/{id}` sobra.
     */
    public function url(string $path): string
    {
        $base = config('polar.server') === 'sandbox' ? self::SANDBOX : self::PRODUCTION;

        return $base.$path;
    }
}
