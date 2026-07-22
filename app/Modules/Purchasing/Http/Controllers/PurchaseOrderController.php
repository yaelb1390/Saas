<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use App\Modules\Purchasing\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Órdenes de compra desde el panel. El dominio ya existía y estaba probado (PurchaseOrderService):
 * aquí solo se le abre una puerta HTTP.
 */
final class PurchaseOrderController extends Controller
{
    public function store(StorePurchaseOrderRequest $request, PurchaseOrderService $orders): RedirectResponse
    {
        $order = $orders->create(CreatePurchaseOrderData::fromArray($request->validated()));

        return back()->with('panel_ok', "Orden de compra {$order->code} creada.");
    }

    /**
     * Recibe la mercancía de la orden: suma la existencia de cada línea al almacén.
     *
     * El route model binding resuelve la orden ya aislada por la empresa activa (una orden de otra
     * empresa devuelve 404).
     */
    public function receive(PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        try {
            $orders->receive($order);
        } catch (DomainException $e) {
            // Traducir la regla de dominio a un mensaje HTTP; la regla («¿se puede recibir?») sigue
            // viviendo en el servicio, no aquí.
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Mercancía de la orden {$order->code} recibida: la existencia ya está en el almacén.");
    }
}
