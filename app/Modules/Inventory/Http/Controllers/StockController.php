<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\DTOs\CreateGoodsReceiptData;
use App\Modules\Inventory\DTOs\ScanUnitData;
use App\Modules\Inventory\Http\Requests\ScanSerialsRequest;
use App\Modules\Inventory\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Http\Requests\UpdateUnitRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\SerialScanService;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\UnitAdjustmentService;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\Inventory\Support\UnitHistory;
use DomainException;
use Illuminate\Contracts\View\View;
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

    /**
     * Buscar una unidad por su serie y ver su historia.
     *
     * Es la pantalla que se abre cuando un cliente vuelve con un aparato: se teclea la serie y sale
     * qué es, cuándo entró y a quién se vendió. Vacío al entrar; con resultado tras buscar.
     */
    public function unitHistory(Request $request): View
    {
        $serie = trim((string) $request->query('serie', ''));

        return view('panel.unit-history', [
            'serie' => $serie,
            'ficha' => $serie === '' ? null : UnitHistory::porSerie($serie),
        ]);
    }

    /**
     * Las unidades disponibles de un producto serializado, para que el terminal deje elegir cuál sale.
     *
     * Devuelve serie, condición, color y su precio de venta —el propio si lo tiene, si no el del
     * catálogo—. Solo las de la empresa activa y en estado disponible: nunca una ya vendida.
     */
    public function availableUnits(Product $product): JsonResponse
    {
        $unidades = $product->units()
            ->where('status', ProductUnit::DISPONIBLE)
            ->orderBy('serial')
            ->get(['id', 'serial', 'condition', 'color', 'price', 'warehouse_id'])
            ->map(fn (ProductUnit $u): array => [
                'serial' => $u->serial,
                'condition' => $u->condition,
                'color' => $u->color,
                'price' => $u->precioDeVenta(),
                'warehouse_id' => $u->warehouse_id,
            ]);

        return response()->json(['units' => $unidades]);
    }

    /**
     * Alta masiva de unidades serializadas por escaneo.
     *
     * Los seriales llegan como un JSON del navegador: una lista de series, con la condicion, el
     * color, el costo y el precio compartidos por toda la tanda. El servicio los convierte en
     * unidades y sube el stock; aqui solo se traduce el resultado a un aviso para el cajero.
     */
    public function scanSerials(ScanSerialsRequest $request, SerialScanService $scan): RedirectResponse
    {
        $producto = Product::query()->findOrFail($request->integer('product_id'));
        $almacen = Warehouse::query()->findOrFail($request->integer('warehouse_id'));

        $crudo = json_decode((string) $request->input('seriales'), true);
        $seriales = [];

        foreach (is_array($crudo) ? $crudo : [] as $fila) {
            $serial = trim((string) ($fila['serial'] ?? ''));

            if ($serial === '') {
                continue;
            }

            $seriales[] = new ScanUnitData(
                serial: $serial,
                condition: $request->input('condition'),
                color: $request->input('color'),
                cost: $request->input('cost'),
                price: $request->input('price'),
            );
        }

        try {
            $resultado = $scan->alta($producto, $almacen, $seriales);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        $rechazados = count($resultado['rechazados']);
        $aviso = $rechazados > 0
            ? sprintf(' Se saltaron %d serie%s repetida%s.', $rechazados, $rechazados === 1 ? '' : 's', $rechazados === 1 ? '' : 's')
            : '';

        return back()->with('panel_ok', sprintf(
            'Se dieron de alta %d unidad%s de %s.%s',
            $resultado['creadas'],
            $resultado['creadas'] === 1 ? '' : 'es',
            $producto->name,
            $aviso,
        ));
    }

    /**
     * La rejilla de TODAS las unidades de la empresa: la que deja borrar la que coló mal, corregir un
     * usado mal tasado o saltar al historial de una serie.
     *
     * Se filtra por producto y por estado. Por omisión salen las disponibles —las que se pueden
     * tocar—; «vendidas» y «todas» son pestañas aparte. Se cargan producto y almacén de una vez para
     * no ir a la base por cada fila.
     */
    public function units(Request $request): View
    {
        $estado = (string) $request->query('estado', 'disponibles');
        $productId = $request->integer('product_id') ?: null;

        $unidades = ProductUnit::query()
            ->with(['product', 'warehouse'])
            ->when($productId, fn ($q, $id) => $q->where('product_id', $id))
            ->when($estado === 'disponibles', fn ($q) => $q->where('status', ProductUnit::DISPONIBLE))
            ->when($estado === 'vendidas', fn ($q) => $q->where('status', ProductUnit::VENDIDA))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('panel.serial-units', [
            'unidades' => $unidades,
            'estado' => $estado,
            'productId' => $productId,
            // Solo los productos que llevan serie tienen unidades: son los del desplegable del filtro.
            'serializados' => Product::query()
                ->where('tracks_serials', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku']),
        ]);
    }

    /**
     * Da de baja una unidad. La regla —solo disponibles, y bajando el stock por la puerta con
     * kardex— vive en el servicio; aquí solo se traduce el «no» a un aviso.
     */
    public function deleteUnit(ProductUnit $unit, UnitAdjustmentService $unidades): RedirectResponse
    {
        $serial = $unit->serial;

        try {
            $unidades->borrar($unit);
        } catch (DomainException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        return back()->with('panel_ok', "Unidad «{$serial}» dada de baja. El stock bajó en uno.");
    }

    /**
     * Corrige precio, condición y color de una unidad. La serie no se toca: el FormRequest ni la
     * admite.
     */
    public function updateUnit(UpdateUnitRequest $request, ProductUnit $unit, UnitAdjustmentService $unidades): RedirectResponse
    {
        $unidades->editar(
            $unit,
            $request->input('condition'),
            $request->input('color'),
            $request->input('price'),
        );

        return back()->with('panel_ok', "Unidad «{$unit->serial}» actualizada.");
    }
}
