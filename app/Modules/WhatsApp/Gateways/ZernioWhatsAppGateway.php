<?php

declare(strict_types=1);

namespace App\Modules\WhatsApp\Gateways;

use App\Modules\Core\Models\Company;
use App\Modules\Social\Enums\SocialPlatform;
use App\Modules\Social\Exceptions\SocialException;
use App\Modules\Social\Services\ZernioClient;
use App\Modules\WhatsApp\Models\WaConversation;
use RuntimeException;

/**
 * WhatsApp por la API oficial de Meta, a través de Zernio.
 *
 * La alternativa a Evolution, y la diferencia no es de proveedor sino de naturaleza: Evolution
 * automatiza la sesión de WhatsApp Web —funciona con cualquier número y en dos minutos, pero Meta
 * puede bloquearlo—, mientras que esto es la API que Meta publica para eso. Sin riesgo de bloqueo,
 * sin servidor propio que mantener y, para un bot que solo contesta, sin coste: Meta no cobra los
 * mensajes de servicio dentro de la ventana de 24 horas.
 *
 * No reimplementa nada: `ZernioClient` ya sabe hablar con la bandeja unificada porque es la misma que
 * atiende los mensajes de Instagram. Lo único propio de aquí es traducir entre el mundo de este
 * módulo —teléfonos— y el de Zernio —conversaciones—.
 */
final class ZernioWhatsAppGateway implements WhatsAppConnection, WhatsAppGateway
{
    public function __construct(private readonly Company $company) {}

    /**
     * Contesta a un número.
     *
     * OJO con la firma: la interfaz habla de teléfonos y Zernio de conversaciones, así que el
     * teléfono se traduce mirando la conversación que abrió el propio cliente al escribir. Si no la
     * hay, NO se inventa un envío: se falla con un motivo que se pueda leer en pantalla.
     *
     * @return array{external_id: ?string, status: string}
     */
    public function sendText(string $phone, string $body): array
    {
        $conversacion = WaConversation::query()->where('phone', $phone)->first();

        $externa = $conversacion?->external_conversation_id;
        $cuenta = $conversacion?->external_account_id;

        if ($externa === null || $cuenta === null) {
            /*
             * Esto NO es un fallo técnico, es la regla de Meta.
             *
             * Por la vía oficial la ventana de conversación la abre el cliente. Antes de que escriba,
             * lo único que se le puede mandar es una plantilla aprobada, que es otro producto —y el
             * que se paga—. La pantalla ya desactiva «escribir a un número nuevo» cuando la empresa
             * va por aquí; este mensaje es para cuando se llegue igual, por una ruta o un reintento.
             */
            throw new RuntimeException(
                'Por la vía oficial de WhatsApp solo se puede responder a quien escribió primero. '
                .'Este número todavía no ha iniciado ninguna conversación.'
            );
        }

        /*
         * La clave de idempotencia va por MENSAJE, no por conversación.
         *
         * En la bienvenida de Instagram se usa la conversación porque allí solo se manda uno en toda
         * su vida. Aquí el bot contesta muchas veces en la misma conversación, y repetir la clave
         * haría que Zernio reconociera el segundo envío como el primero y no mandara nada.
         */
        (new ZernioClient($this->company))->enviarMensaje(
            $externa,
            $cuenta,
            $body,
            'wa-'.$this->company->id.'-'.substr(sha1($externa.'|'.$body.'|'.microtime(true)), 0, 24),
        );

        // Zernio no devuelve el identificador del mensaje creado, así que no se inventa uno: se deja
        // vacío antes que guardar algo que no sirva para encontrarlo después.
        return ['external_id' => null, 'status' => 'sent'];
    }

    /**
     * ¿Hay una cuenta de WhatsApp conectada?
     *
     * Sale del listado de cuentas, no de un estado de sesión: aquí no hay sesión que se caiga, hay
     * una autorización de Meta que sigue viva o que hay que renovar.
     *
     * @return array{state: string, instance: string, connected: bool}
     */
    public function status(): array
    {
        $cuenta = $this->cuenta();

        if ($cuenta === null) {
            return ['state' => 'missing', 'instance' => 'WhatsApp Business', 'connected' => false];
        }

        // `necesita_reconectar` lo calcula el cliente a partir de lo que informa Zernio: la
        // autorización caducó o el cliente la revocó desde Meta.
        $caida = ($cuenta['necesita_reconectar'] ?? false) === true;

        return [
            'state' => $caida ? 'close' : 'open',
            'instance' => (string) ($cuenta['name'] ?? 'WhatsApp Business'),
            'connected' => ! $caida,
        ];
    }

    /**
     * Empieza el alta con Meta.
     *
     * Devuelve una DIRECCIÓN, no un QR, y por eso el contrato tiene las dos claves: aquí el negocio
     * se va a Meta a elegir su cuenta y su número, y vuelve conectado. No hay nada que escanear.
     *
     * @return array{state: string, qr: ?string, url: ?string}
     */
    public function connect(): array
    {
        if ($this->status()['connected']) {
            return ['state' => 'open', 'qr' => null, 'url' => null];
        }

        return [
            'state' => 'connecting',
            'qr' => null,
            'url' => (new ZernioClient($this->company))->connectUrl(SocialPlatform::WhatsApp),
        ];
    }

    /**
     * Desconecta la cuenta en Zernio.
     *
     * No revoca nada en Meta —eso se hace desde el Business Manager del negocio, y es suyo—: deja de
     * estar conectada aquí, que es lo que el botón promete.
     */
    public function logout(): bool
    {
        $cuenta = $this->cuenta();

        if ($cuenta === null) {
            return false;
        }

        return (new ZernioClient($this->company))->desconectarCuenta((string) $cuenta['id']);
    }

    /**
     * La cuenta de WhatsApp de esta empresa, o null.
     *
     * No lanza: que Zernio no conteste tiene que dejar la bandeja utilizable, igual que cuando
     * Evolution está caído. Se lee lo que hay guardado y se contesta a mano.
     *
     * @return array<string, mixed>|null
     */
    private function cuenta(): ?array
    {
        try {
            $cliente = new ZernioClient($this->company);

            if (! $cliente->isConfigured()) {
                return null;
            }

            foreach ($cliente->accounts() as $cuenta) {
                if (($cuenta['platform'] ?? null) === SocialPlatform::WhatsApp->value) {
                    return $cuenta;
                }
            }
        } catch (SocialException) {
            return null;
        }

        return null;
    }
}
