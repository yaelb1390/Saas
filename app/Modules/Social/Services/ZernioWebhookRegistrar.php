<?php

declare(strict_types=1);

namespace App\Modules\Social\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\DbTable;
use App\Modules\Social\Models\SocialWelcomeSetting;
use App\Modules\WhatsApp\Models\WaBotSetting;

/**
 * Quién necesita que Zernio nos avise, y por tanto si el webhook debe existir.
 *
 * Existe porque el webhook dejó de ser de una sola función. Antes lo daba de alta la bienvenida de
 * Instagram al encenderla y lo borraba al apagarla, y eso está bien mientras sea la única que
 * escucha. Con el bot de WhatsApp entrando por la MISMA dirección, ese acoplamiento se convierte en
 * dos fallos de verdad:
 *
 *  · Apagar la bienvenida borraría el webhook y el bot de WhatsApp dejaría de recibir mensajes, sin
 *    un solo aviso y sin que nadie relacionara una cosa con la otra.
 *  · Un negocio que use WhatsApp pero no Instagram no tendría webhook nunca, así que su bot no se
 *    enteraría de un solo mensaje.
 *
 * La regla es simple: se da de alta cuando lo necesite CUALQUIERA de los dos, y solo se borra cuando
 * no lo necesite NINGUNO.
 *
 * El token y el secreto siguen viviendo en `social_welcome_settings` y no se mueven a propósito: la
 * dirección registrada en Zernio LLEVA EL TOKEN DENTRO. Mudarlos obligaría a re-registrar todos los
 * webhooks vivos, y cualquier fallo en esa mudanza dejaría negocios sin recibir mensajes sin que se
 * notara. Lo que cambia es quién decide, no dónde se guarda.
 */
final class ZernioWebhookRegistrar
{
    public function __construct(private readonly Company $company) {}

    /**
     * Pone el webhook al día con lo que hace falta ahora mismo.
     *
     * No lanza: se llama al guardar ajustes, y no poder hablar con Zernio no debe impedir guardar lo
     * que el usuario acaba de escribir. Devuelve si el webhook quedó activo.
     */
    public function sincronizar(): bool
    {
        if (! DbTable::existe('social_welcome_settings')) {
            return false;
        }

        $ajustes = SocialWelcomeSetting::paraEmpresa((int) $this->company->id);
        $hace_falta = $this->haceFalta();
        $existe = $ajustes->zernio_webhook_id !== null;

        if ($hace_falta === $existe) {
            return $existe;
        }

        $cliente = new ZernioClient($this->company);

        if ($hace_falta) {
            $ajustes->zernio_webhook_id = $cliente->registrarWebhook(
                route('webhooks.social', $ajustes->token),
                (string) $ajustes->secret,
            );
        } else {
            $cliente->borrarWebhook((string) $ajustes->zernio_webhook_id);
            $ajustes->zernio_webhook_id = null;
        }

        $ajustes->save();

        return $ajustes->zernio_webhook_id !== null;
    }

    /** ¿Hay algo encendido que dependa de recibir avisos? */
    public function haceFalta(): bool
    {
        $companyId = (int) $this->company->id;

        if (SocialWelcomeSetting::paraEmpresa($companyId)->is_active) {
            return true;
        }

        // El bot de WhatsApp, solo si va por la vía oficial: por la del QR los mensajes llegan por
        // el webhook de Evolution y Zernio no pinta nada.
        if (! DbTable::existe('wa_bot_settings')) {
            return false;
        }

        $bot = WaBotSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();

        return $bot !== null && $bot->usaZernio() && $bot->is_active;
    }
}
