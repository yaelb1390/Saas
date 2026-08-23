<?php

declare(strict_types=1);

namespace App\Modules\Social\Services;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Support\DbTable;
use App\Modules\Social\Exceptions\SocialException;
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
            $url = route('webhooks.social', $ajustes->token);

            /*
             * Una dirección que Zernio no puede alcanzar NO se registra.
             *
             * Zernio corre en la nube: `localhost` no es este equipo, es el suyo. Registrar esa
             * dirección no da error —se acepta sin más— y el resultado es el peor posible: los
             * mensajes se entregan en otro sitio, aquí no llega ninguno, y no hay nada que mirar
             * para entender por qué. Pasó de verdad, y costó encontrarlo.
             *
             * Se prefiere fallar con un motivo legible antes que dejar registrada una dirección
             * muerta que además ensucia el panel de Zernio con errores de entrega.
             */
            if (! self::alcanzable($url)) {
                throw SocialException::webhookInalcanzable($url);
            }

            $ajustes->zernio_webhook_id = $cliente->registrarWebhook($url, (string) $ajustes->secret);
        } else {
            $cliente->borrarWebhook((string) $ajustes->zernio_webhook_id);
            $ajustes->zernio_webhook_id = null;
        }

        $ajustes->save();

        return $ajustes->zernio_webhook_id !== null;
    }

    /**
     * ¿Puede Zernio llegar a esta dirección desde fuera?
     *
     * No se comprueba haciendo una petición: eso diría si el sitio responde DESDE AQUÍ, que es
     * justo lo que no interesa —`localhost` responde perfectamente desde este equipo—. Lo que hay
     * que descartar son los nombres que solo significan algo dentro de la máquina o de la red local.
     *
     * Se admite `http` a propósito: hay quien publica detrás de un proxy que corta el TLS, y
     * rechazarlo por el esquema dejaría fuera instalaciones que funcionan.
     */
    public static function alcanzable(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        // Nombres que nunca salen de la máquina, y los dominios reservados para pruebas.
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'host.docker.internal'], true)) {
            return false;
        }

        foreach (['.local', '.test', '.localhost', '.internal'] as $sufijo) {
            if (str_ends_with($host, $sufijo)) {
                return false;
            }
        }

        // Direcciones IP de red privada: 10.x, 192.168.x y 172.16–31.x.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        // Un nombre sin punto tampoco existe fuera: `web`, `app`, el nombre de un contenedor.
        return str_contains($host, '.');
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
