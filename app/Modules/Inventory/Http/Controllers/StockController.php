<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\DTOs\CreateGoodsReceiptData;
use App\Modules\Inventory\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Entrada de mercancía al almacén.
 *
 * Delgado: valida, delega en GoodsReceiptService y traduce las reglas de dominio a mensajes. El
 * servicio existe —antes esto llamaba directamente a StockService— porque una remesa es más que
 * sumar existencia: es un documento con sus líneas, su proveedor y su costo, y las tres cosas tienen
 * que entrar juntas o no entrar.
 *
 * StockService sigue siendo la única puerta al stock; GoodsReceiptService pasa por él.
 */
final class StockController extends Controller
{
    /**
     * Resuelve el código escaneado (o tecleado). Endpoint propio del inventario, no el del POS:
     * reutilizar aquel ataría el almacén al módulo «pos» y al permiso «pos.operate», y se puede
     * inventariar sin vender. Ambos comparten el presenter, así que la forma es la misma.
     */
    public function lookup(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        return response()->json($lookup->payload((string) $request->query('codigo', '')));
    }

    /**
     * Confirma la remesa entera.
     *
     * Antes cada producto era un envío y una recarga de página; una remesa de treinta artículos eran
     * treinta viajes al servidor y, si el almacenista se distraía a la mitad, quedaban quince dentro
     * y quince fuera sin nada que dijera cuáles.
     */
    /**
     * Poner la existencia en lo que se acaba de contar.
     *
     * Va aparte de la entrada de mercancía porque son cosas distintas: una entrada es una compra con
     * su proveedor y su costo, y esto es un conteo —que además puede ir hacia abajo, cuando falta—.
     *
     * Exige `stock.adjust`, el mismo permiso que dar entrada: quien puede mover existencias puede
     * tapar un faltante, y por eso el cajero no lo tiene.
     */
    public function count(Request $request, Product $product, StockCountService $conteo): RedirectResponse
    {
        $datos = $request->validate([
            'counted' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'counted.required' => 'Escribe cuántos hay de verdad.',
        ]);

        try {
            $movimiento = $conteo->ajustar($product, (string) $datos['counted'], $datos['note'] ?? null);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', sprintf(
            'Existencia de «%s» ajustada a %s.',
            $product->name,
            rtrim(rtrim((string) $movimiento->quantity_after, '0'), '.'),
        ));
    }

    public function store(StoreGoodsReceiptRequest $request, GoodsReceiptService $remesas): RedirectResponse
    {
        try {
            $remesa = $remesas->create(CreateGoodsReceiptData::fromArray($request->validated()));
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        $lineas = $remesa->lines->count();
        $actualizados = $remesa->lines->where('cost_updated', true)->count();

        $aviso = $actualizados > 0
            ? sprintf(' Se actualizó el costo de %d producto%s.', $actualizados, $actualizados === 1 ? '' : 's')
            : '';

        return back()->with('panel_ok', sprintf(
            'Entrada %s registrada: %d %s.%s',
            $remesa->code,
            $lineas,
            $lineas === 1 ? 'producto' : 'productos',
            $aviso,
        ));
    }
}
