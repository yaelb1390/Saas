<?php

declare(strict_types=1);

namespace App\Modules\POS\DTOs;

/**
 * Los datos del reparto que el cajero teclea al cobrar un pedido con envío.
 *
 * Va aparte de `CreateSaleData` a propósito: una venta no sabe de direcciones ni de motoristas, y
 * meterle campos de reparto la ataría al módulo de entregas para siempre —incluida la de una
 * ferretería que no reparte nada—.
 *
 * `employeeId` null significa «que lo decida el sistema», no «sin repartidor»: es lo que dispara la
 * asignación automática. Para dejarla sin asignar a propósito está `sinAsignar`.
 */
final readonly class DeliveryOrderData
{
    public function __construct(
        public string $address,
        public ?string $customerName = null,
        public ?string $phone = null,
        public ?string $notes = null,
        public ?int $employeeId = null,
        public bool $sinAsignar = false,
        // ¿Lo cobra el motorista en la puerta? Si no, el pedido ya se pagó y sale con «a cobrar 0».
        public bool $cobraElMotorista = false,
    ) {}
}
