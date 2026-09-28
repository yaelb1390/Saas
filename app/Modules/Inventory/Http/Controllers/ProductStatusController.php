<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Product;
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
    public function __invoke(Request $request, Product $product): RedirectResponse
    {
        // Sin `panel_ok`: el interruptor ya se ve cambiado en la propia fila, y un aviso por
        // cada clic estorba más de lo que informa en una acción tan frecuente como esta.
        $product->update(['is_active' => $request->boolean('is_active')]);

        return back();
    }
}
