<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

/**
 * Por qué se cerró una entrega.
 *
 * En la pantalla del repartidor EL MOTIVO ES LO QUE SE PULSA: no elige primero un estado abstracto
 * y luego una razón, elige lo que le pasó y el estado sale de aquí. Un motorista de pie en la calle
 * no tiene por qué saber la diferencia entre «fallida» y «cancelada»; sí sabe si no había nadie o
 * si el cliente le dijo que ya no lo quería.
 *
 * Por eso cada motivo declara a qué estado lleva: es la única definición y la vista solo pinta
 * botones. Añadir un motivo sin decidir su estado no compila.
 */
enum DeliveryOutcomeReason: string
{
    // --- Se entregó ---
    case Delivered = 'delivered';

    // --- Fue y no pudo. La mercancía vuelve. ---
    case NotHome = 'not_home';
    case WrongAddress = 'wrong_address';
    case NoAnswer = 'no_answer';
    case NoMoney = 'no_money';
    case FailedOther = 'failed_other';

    // --- El pedido se anuló. ---
    case Refused = 'refused';
    case CustomerCancelled = 'customer_cancelled';
    case CancelledOther = 'cancelled_other';

    /** Lo que lee el repartidor en el botón. Frases de la calle, no del manual. */
    public function label(): string
    {
        return match ($this) {
            self::Delivered => 'Entregada',
            self::NotHome => 'No estaba nadie',
            self::WrongAddress => 'La dirección está mala',
            self::NoAnswer => 'No contestó el teléfono',
            self::NoMoney => 'No tenía el dinero',
            self::FailedOther => 'Otra cosa',
            self::Refused => 'La rechazó en la puerta',
            self::CustomerCancelled => 'La canceló antes',
            self::CancelledOther => 'Otra cosa',
        };
    }

    /** El estado en el que queda la entrega. */
    public function status(): DeliveryStatus
    {
        return match ($this) {
            self::Delivered => DeliveryStatus::Delivered,
            self::NotHome, self::WrongAddress, self::NoAnswer, self::NoMoney, self::FailedOther => DeliveryStatus::Failed,
            self::Refused, self::CustomerCancelled, self::CancelledOther => DeliveryStatus::Cancelled,
        };
    }

    /**
     * Motivos que ofrece cada cierre no entregado.
     *
     * @return array<int, self>
     */
    public static function para(DeliveryStatus $status): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $motivo): bool => $motivo->status() === $status,
        ));
    }
}
