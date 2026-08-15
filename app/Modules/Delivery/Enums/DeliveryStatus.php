<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

/**
 * Estados por los que pasa una entrega: pendiente → asignada → en camino → entregada, con «fallida»
 * como la otra salida.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Assigned => 'Asignada',
            self::InTransit => 'En camino',
            self::Delivered => 'Entregada',
            self::Failed => 'No se pudo entregar',
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
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed], true);
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
     * «no se pudo entregar» desde cualquier punto abierto, porque el motorista puede encontrarse la
     * casa cerrada en cualquier momento del camino.
     */
    public function admiteIr(self $destino): bool
    {
        if ($this === $destino) {
            return true;
        }

        if ($this->isFinal()) {
            return false;
        }

        if ($destino === self::Failed) {
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
