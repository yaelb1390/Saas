<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * «Hoy no hay»: enciende y apaga la disponibilidad de un producto.
 *
 * Existe aparte de la edición del producto por dos motivos:
 *
 *  · Es la acción de un CAJERO en mitad del turno —se acabó el guineo—, no la de quien mantiene el
 *    catálogo. Va con `products.view`, que es lo que ya tiene quien opera el terminal, y no con
 *    `products.manage`: apagar algo que se acabó es operación, retirar un producto del catálogo es
 *    otra cosa y sigue exigiendo permiso de gestión.
 *  · Se usa desde la rejilla táctil, donde el cajero no puede abrir un formulario entero con una
 *    mano y una funda de comida en la otra.
 */
final class ProductAvailabilityController extends Controller
{
    public function __invoke(Request $request, Product $product): RedirectResponse|JsonResponse
    {
        $disponible = $request->boolean('is_available');

        $product->update(['is_available' => $disponible]);

        $mensaje = $disponible
            ? "«{$product->name}» vuelve a estar disponible."
            : "«{$product->name}» queda marcado como agotado.";

        // La rejilla del terminal cobra por fetch y no recarga: sacarla de pantalla completa cada vez
        // que se acaba algo sería peor que el problema que resuelve.
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensaje, 'is_available' => $disponible]);
        }

        return back()->with('panel_ok', $mensaje);
    }
}
