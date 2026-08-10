<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Controllers;

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\CategoryIcons;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\POS\Models\HeldOrder;
use App\Modules\POS\Services\HeldOrderService;
use App\Modules\POS\Support\KioskMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Punto de venta táctil, pensado para heladería y comida rápida.
 *
 * A diferencia del POS de mostrador (que parte de escanear un código), aquí el cajero ve el catálogo
 * y toca. Por eso la rejilla se carga sin buscar y se filtra por categoría.
 *
 * El cobro NO se reimplementa: el formulario apunta a `panel.pos.checkout`, que ya valida, relee los
 * precios en el servidor, descuenta stock y registra el movimiento de caja. Una sola vía de cobro
 * para ambas pantallas evita que se separen con el tiempo, que es lo que ya ocurrió entre el POS y
 * el mostrador de repuestos.
 */
final class QuickPosController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): View
    {
        return view('panel.quick-pos', [
            // Un cajero ve el terminal a pantalla completa, sin menú ni barra: se concentra en
            // vender. El dueño ve la misma pantalla dentro del panel de siempre.
            'kiosk' => KioskMode::applies($request->user(), $currentCompany->model()),
            'openSession' => CashSession::query()
                ->where('status', CashSessionStatus::Open)->latest('opened_at')->first(),
            'categories' => $this->categoriasConProductos(),
        ]);
    }

    /**
     * Categorías que se ofrecen en la barra lateral.
     *
     * Solo las que tienen algo que vender: una entrada sin productos es un filtro que no lleva a
     * ninguna parte. Por eso la barra crece sola al dar de alta productos y se encoge al retirarlos,
     * sin que nadie tenga que mantener una lista aparte.
     *
     * @return array<int, array{id: int, name: string, icon: string}>
     */
    private function categoriasConProductos(): array
    {
        return Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'icon'])
            ->map(fn (Category $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                // El icono se resuelve aquí para que el cliente no tenga que conocer el genérico.
                'icon' => CategoryIcons::resolve($c->icon),
            ])
            ->all();
    }

    /**
     * Catálogo de la rejilla. Paginado a propósito: cargar el surtido entero de una vez es
     * justamente lo que se retiró del POS de mostrador por rendimiento.
     */
    public function catalog(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        $categoryId = $request->integer('category') ?: null;

        return response()->json([
            ...$lookup->catalog($categoryId),
            // Las categorías viajan con el catálogo para que un terminal abierto toda la jornada vea
            // aparecer y desaparecer entradas al ritmo del inventario, sin recargar la página.
            'categories' => $this->categoriasConProductos(),
        ]);
    }

    /**
     * Aparca el pedido en curso para atender a otro cliente.
     *
     * No descuenta stock: hasta que se cobra, la mercancía sigue disponible para cualquiera. Reservar
     * sería peor —un pedido olvidado dejaría producto bloqueado sin que nadie sepa por qué—.
     */
    public function hold(Request $request, HeldOrderService $held): JsonResponse
    {
        $request->validate([
            'cart' => ['required', 'array', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $held->hold(
            $request->array('cart'),
            $request->filled('customer_name') ? $request->string('customer_name')->toString() : null,
        );

        return response()->json([
            'reference' => $order->reference,
            'pending' => $this->aparcados($held),
        ], 201);
    }

    /** Pedidos aparcados pendientes de cobro. */
    public function pending(HeldOrderService $held): JsonResponse
    {
        return response()->json(['pending' => $this->aparcados($held)]);
    }

    /**
     * Devuelve el pedido aparcado con los precios de HOY, listo para volver al ticket.
     *
     * El route model binding lo resuelve ya aislado por empresa: uno ajeno da 404.
     */
    public function resume(HeldOrder $heldOrder, HeldOrderService $held): JsonResponse
    {
        return response()->json([
            'reference' => $heldOrder->reference,
            'customer_name' => $heldOrder->customer_name,
            'cart' => $held->resume($heldOrder),
        ]);
    }

    /** Descarta un pedido aparcado que el cliente ya no va a pagar. */
    public function discard(HeldOrder $heldOrder, HeldOrderService $held): JsonResponse
    {
        $held->discard($heldOrder);

        return response()->json(['pending' => $this->aparcados($held)]);
    }

    /**
     * Lista ligera para la barra de aparcados: solo lo que se pinta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aparcados(HeldOrderService $held): array
    {
        return $held->pending()->map(fn (HeldOrder $o): array => [
            'id' => (int) $o->id,
            'reference' => (string) $o->reference,
            'customer_name' => $o->customer_name,
            'total' => (string) $o->total,
            'items' => count($o->payload),
            'at' => $o->created_at?->format('H:i'),
        ])->all();
    }

    /**
     * Tamaños, sabores y extras de un producto, para el paso de elección.
     *
     * Se piden al tocar el producto y no junto al catálogo: cargar las opciones de los 60 productos
     * de una rejilla para usar las de uno solo sería tirar la mayor parte a la basura.
     *
     * El route model binding ya resuelve el producto aislado por empresa (uno ajeno da 404).
     */
    public function options(Product $product): JsonResponse
    {
        $groups = $product->optionGroups()
            ->where('option_groups.is_active', true)
            ->with(['options' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(fn (OptionGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'multiple' => $group->isMultiple(),
                'min' => $group->minRequired(),
                'max' => $group->maxAllowed() === PHP_INT_MAX ? null : $group->maxAllowed(),
                'options' => $group->options->map(fn (Option $option): array => [
                    'id' => $option->id,
                    'name' => $option->name,
                    // Solo para pintar el «+60» junto a la opción: el precio real lo recalcula el
                    // servidor al cobrar, igual que el del producto.
                    'price_delta' => (string) $option->price_delta,
                ])->values(),
            ])
            ->values();

        return response()->json(['groups' => $groups]);
    }
}
