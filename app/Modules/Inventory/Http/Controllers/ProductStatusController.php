<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Activa o retira un producto del catálogo, sin abrir el formulario entero.
 *
 * Va con `products.manage` y no con `products.view` —a diferencia de la disponibilidad de hoy
 * (ver ProductAvailabilityController)—: esto SÍ es retirar un producto del catálogo, la misma
 * decisión que ya exige permiso de gestión en cualquier otro sitio de esta pantalla.
 */
final class ProductStatusController extends Controller
{
    public function __invoke(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $activo = $request->boolean('is_active');

        $product->update(['is_active' => $activo]);

        // La lista de inventario cambia el interruptor por fetch y no por formulario: un JSON aquí
        // evita el «pantallazo» de una recarga completa por un solo clic. Sin `panel_ok` tampoco: el
        // interruptor ya se ve cambiado en la propia fila.
        if ($request->expectsJson()) {
            return response()->json(['is_active' => $activo]);
        }

        return back();
    }
}
