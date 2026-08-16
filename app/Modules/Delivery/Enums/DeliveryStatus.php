<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

/**
 * Estados por los que pasa una entrega: pendiente → asignada → en camino → entregada, con dos
 * salidas más.
 *
 * «No entregada» y «cancelada» NO son matices del mismo final:
 *
 *   · No entregada → el motorista fue y no pudo. La mercancía vuelve y el pedido sigue vivo para
 *     el negocio: se puede reintentar mañana.
 *   · Cancelada    → el pedido se anuló, por el cliente o por el negocio. Puede no haber salido
 *     siquiera del local.
 *
 * Contarlas juntas taparía la única pregunta que importa al cerrar el día: ¿cuánto se dejó de
 * vender por culpa nuestra y cuánto porque el cliente cambió de idea?
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Assigned => 'Asignada',
            self::InTransit => 'En camino',
            self::Delivered => 'Entregada',
            self::Failed => 'No se pudo entregar',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Clase de color, de las que existen en app.css. */
    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'badge-gray',
            self::Assigned => 'badge-blue',
            self::InTransit => 'badge-amber',
            self::Delivered => 'badge-green',
            self::Failed => 'badge-red',
            // Violeta y no rojo: cancelar no es un fallo del reparto, es una decisión.
            self::Cancelled => 'badge-violet',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Cancelled], true);
    }

    /** Alias en español, para leer el servicio sin cambiar de idioma a media frase. */
    public function esFinal(): bool
    {
        return $this->isFinal();
    }

    /**
     * ¿Se puede pasar de este estado al otro?
     *
     * Sin esto, una entrega ya entregada podía volver a «pendiente» con solo repetir una petición, y
     * el reparto del día dejaba de cuadrar sin que nadie hubiera hecho nada raro. Se admite marcar
     * «no se pudo entregar» y «cancelada» desde cualquier punto abierto: el motorista puede
     * encontrarse la casa cerrada en cualquier momento del camino, y el cliente puede llamar para
     * anular antes incluso de que el pedido salga del local.
     */
    public function admiteIr(self $destino): bool
    {
        if ($this === $destino) {
            return true;
        }

        if ($this->isFinal()) {
            return false;
        }

        if ($destino === self::Failed || $destino === self::Cancelled) {
            return true;
        }

        return match ($this) {
            self::Pending => $destino === self::Assigned,
            self::Assigned => $destino === self::InTransit || $destino === self::Delivered,
            self::InTransit => $destino === self::Delivered,
            default => false,
        };
    }

    /**
     * Estados en los que la entrega sigue viva. Es lo que cuenta la campana de alertas.
     *
     * @return array<int, self>
     */
    public static function abiertas(): array
    {
        return [self::Pending, self::Assigned, self::InTransit];
    }
}
