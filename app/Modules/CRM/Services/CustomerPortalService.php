<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Exceptions\CustomerPortalException;
use App\Modules\CRM\Models\Customer;
use App\Modules\WhatsApp\Services\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Acceso del cliente a su portal.
 *
 * El cliente no es un usuario del sistema: no tiene contraseña ni sesión. En vez de crear un guard
 * y obligarlo a gestionar una credencial más, se le entrega un enlace firmado y con caducidad por
 * el canal que ya usa con el negocio (WhatsApp).
 *
 * Por qué la firma basta como autenticación:
 *
 * - La firma de Laravel es un HMAC sobre la URL completa con la APP_KEY. Cambiar el id del cliente
 *   en la barra de direcciones invalida la firma: no se puede saltar de un cliente a otro.
 * - La caducidad limita la ventana si el enlace se reenvía o queda en el historial del chat.
 *
 * Contrapartida aceptada: quien tenga el enlace, entra (igual que un enlace de «restablecer
 * contraseña»). Por eso el portal es de solo lectura y la caducidad es corta.
 */
final class CustomerPortalService
{
    /**
     * Vigencia por defecto del enlace. Suficiente para que el cliente lo abra con calma, corta como
     * para que un chat reenviado no sea una puerta abierta indefinida.
     */
    public const DEFAULT_TTL_DAYS = 7;

    public function __construct(private readonly WhatsAppService $whatsapp) {}

    /**
     * Genera el enlace firmado y temporal del portal de un cliente.
     */
    public function linkFor(Customer $customer, ?int $ttlDays = null): string
    {
        return URL::temporarySignedRoute(
            'portal.customer',
            Carbon::now()->addDays($ttlDays ?? self::DEFAULT_TTL_DAYS),
            ['customer' => $customer->id],
        );
    }

    /**
     * Envía el enlace al cliente por WhatsApp. El envío real lo hace un Job en cola
     * (WhatsAppService), así que la petición no se bloquea si Evolution tarda o está caído.
     */
    public function sendLink(Customer $customer, ?int $ttlDays = null): string
    {
        $phone = (string) $customer->phone;

        if (trim($phone) === '') {
            throw CustomerPortalException::withoutPhone($customer->id);
        }

        $link = $this->linkFor($customer, $ttlDays);
        $days = $ttlDays ?? self::DEFAULT_TTL_DAYS;

        $this->whatsapp->sendText($phone, implode("\n\n", [
            "Hola {$customer->name}, aquí puedes consultar tus facturas, compras y entregas:",
            $link,
            "El enlace es personal y vence en {$days} días.",
        ]));

        return $link;
    }
}
