<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Services;

use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Events\DeliveryStatusChanged;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Sales\Models\Sale;

/**
 * Gestión de entregas: alta (opcionalmente desde una venta), asignación de repartidor y
 * transición de estados, emitiendo un evento en cada cambio.
 */
final class DeliveryService
{
    public function create(
        string $address,
        ?string $customerName = null,
        ?string $phone = null,
        ?Sale $sale = null,
        ?Customer $customer = null,
    ): Delivery {
        $companyId = (int) ($sale !== null ? $sale->company_id : app(CurrentCompany::class)->id());

        // El cliente explícito manda; si no viene, se hereda el de la venta que origina el reparto.
        $customer ??= $sale?->customer;

        $delivery = new Delivery([
            'company_id' => $companyId,
            'customer_id' => $customer?->id,
            'sale_id' => $sale?->id,
            'code' => $this->nextCode($companyId),
            'status' => DeliveryStatus::Pending,
            'customer_name' => $customerName ?? $sale->customer_name ?? $customer?->name,
            'phone' => $phone ?? $customer?->phone,
            'address' => $address,
            'user_id' => auth()->id(),
        ]);
        $delivery->save();

        return $delivery;
    }

    public function assign(Delivery $delivery, string $driverName): Delivery
    {
        $delivery->fill(['driver_name' => $driverName, 'assigned_at' => now()]);

        return $this->transition($delivery, DeliveryStatus::Assigned);
    }

    public function transition(Delivery $delivery, DeliveryStatus $to): Delivery
    {
        $from = $delivery->status;

        $delivery->status = $to;

        if ($to === DeliveryStatus::Delivered || $to === DeliveryStatus::Failed) {
            $delivery->delivered_at = now();
        }

        $delivery->save();

        DeliveryStatusChanged::dispatch($delivery, $from, $to);

        return $delivery;
    }

    private function nextCode(int $companyId): string
    {
        $count = Delivery::withoutCompanyScope()->where('company_id', $companyId)->count();

        return 'ENV-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }
}
