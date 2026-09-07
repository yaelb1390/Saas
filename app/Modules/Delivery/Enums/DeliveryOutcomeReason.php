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
    /*
     * RETIRADO, PERO NO BORRADO. El repartidor ya no cobra —lo hace la empresa—, así que «no tenía
     * el dinero» dejó de ser un motivo posible. Se deja de ofrecer en `para()`, pero el caso sigue
     * existiendo: hay entregas viejas guardadas con él, y borrarlo las dejaría sin explicación y
     * reventaría al leerlas.
     */
    case NoMoney = 'no_money';
    case ProductIssue = 'product_issue';
    case Unreachable = 'unreachable';
    case FailedOther = 'failed_other';

    // --- El pedido se anuló. ---
    case Refused = 'refused';
    case CustomerCancelled = 'customer_cancelled';
    case MerchantReturn = 'merchant_return';
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
            self::ProductIssue => 'El producto venía con algo',
            self::Unreachable => 'No se puede entrar a la zona',
            self::FailedOther => 'Otra cosa',
            self::Refused => 'La rechazó en la puerta',
            self::CustomerCancelled => 'La canceló antes',
            self::MerchantReturn => 'El comercio pidió que volviera',
            self::CancelledOther => 'Otra cosa',
        };
    }

    /** El estado en el que queda la entrega. */
    public function status(): DeliveryStatus
    {
        return match ($this) {
            self::Delivered => DeliveryStatus::Delivered,
            self::NotHome, self::WrongAddress, self::NoAnswer, self::NoMoney,
            self::ProductIssue, self::Unreachable, self::FailedOther => DeliveryStatus::Failed,
            self::Refused, self::CustomerCancelled,
            self::MerchantReturn, self::CancelledOther => DeliveryStatus::Cancelled,
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
            static fn (self $motivo): bool => $motivo->status() === $status && $motivo->seOfrece(),
        ));
    }

    /**
     * ¿Se le sigue ofreciendo al repartidor?
     *
     * Un motivo se RETIRA, no se borra: las entregas ya cerradas con él tienen que poder seguir
     * explicándose. Hoy solo está retirado «no tenía el dinero», porque el repartidor dejó de cobrar.
     */
    public function seOfrece(): bool
    {
        return $this !== self::NoMoney;
    }
}
