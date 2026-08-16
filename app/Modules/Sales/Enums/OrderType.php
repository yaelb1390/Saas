<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Cómo se lleva el cliente lo que compró.
 *
 * Hasta ahora el punto de venta no lo preguntaba, así que un pedido para una casa se cobraba igual que
 * uno para comer allí y alguien tenía que crear la entrega a mano, copiando la dirección de un papel.
 *
 * «Para llevar» no es un adorno: en comida rápida es la mitad de los pedidos y se confundía con
 * «comer aquí». Separarlos es lo que permite responder después cuánto se vende de cada forma, que es
 * una decisión de negocio —cuántas mesas, cuántos motoristas— y no un dato de curiosidad.
 */
enum OrderType: string
{
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::DineIn => 'Para comer aquí',
            self::Takeaway => 'Para llevar',
            self::Delivery => 'Con envío',
        };
    }

    /** Clase de color, de las que existen en app.css. */
    public function badge(): string
    {
        return match ($this) {
            self::DineIn => 'badge-gray',
            self::Takeaway => 'badge-blue',
            self::Delivery => 'badge-violet',
        };
    }

    /**
     * ¿Este pedido hay que llevarlo a algún sitio?
     *
     * Es la única pregunta que cambia lo que pasa al cobrar: solo el envío crea una entrega. Los otros
     * dos se anotan y ya. Va aquí y no en un `if` del controlador para que añadir un tipo nuevo
     * obligue a decidirlo.
     */
    public function generaEntrega(): bool
    {
        return $this === self::Delivery;
    }
}
