<?php

declare(strict_types=1);

namespace App\Modules\SocialCommerce\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\Social\Services\ZernioWebhookRegistrar;
use App\Modules\SocialCommerce\Models\Settings;

/**
 * Da de alta o de baja el webhook propio de Social Commerce en Zernio, según `is_active`.
 *
 * Webhook DISTINTO del de `Social` (una dirección, un secreto y un `zernio_webhook_id` propios en
 * `social_commerce_settings`): así una empresa puede activar Social Commerce sin depender de que
 * `social` también esté contratado, y viceversa (arquitectura, sección 2).
 */
final class WebhookRegistrar
{
    public function __construct(private readonly Company $company) {}

    /** Pone el webhook al día con lo que hace falta ahora mismo. No lanza. */
    public function sincronizar(): bool
    {
        $ajustes = Settings::paraEmpresa((int) $this->company->id);
        $existe = $ajustes->zernio_webhook_id !== null;

        if ($ajustes->is_active === $existe) {
            return $existe;
        }

        $cliente = new ZernioClient($this->company);

        try {
            if ($ajustes->is_active) {
                $url = route('webhooks.social-commerce', $ajustes->webhook_token);

                // Misma comprobación que el registrador de Social: una dirección que Zernio no
                // pueda alcanzar no se registra, porque el fallo sería silencioso (los avisos se
                // entregarían en otro sitio y aquí no llegaría ninguno).
                if (! ZernioWebhookRegistrar::alcanzable($url)) {
                    throw SocialException::webhookInalcanzable($url);
                }

                $ajustes->zernio_webhook_id = $cliente->registrarWebhook($url, (string) $ajustes->webhook_secret);
            } else {
                $cliente->borrarWebhook((string) $ajustes->zernio_webhook_id);
                $ajustes->zernio_webhook_id = null;
            }
        } catch (SocialException $e) {
            // Se deshace el interruptor: si el webhook no se pudo dar de alta, encenderlo en el
            // panel sería mentir sobre si de verdad va a recibir algo.
            $ajustes->is_active = $existe;

            throw $e;
        } finally {
            $ajustes->save();
        }

        return $ajustes->zernio_webhook_id !== null;
    }
}
