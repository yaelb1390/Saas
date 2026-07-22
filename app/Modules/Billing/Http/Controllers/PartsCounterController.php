<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\FiscalSequenceException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Http\Requests\IssuePartsInvoiceRequest;
use App\Modules\Billing\Services\CounterInvoiceService;
use App\Modules\Core\Models\Warehouse;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Mostrador de repuestos: pantalla de facturación directa. Busca piezas, arma el ticket y emite el
 * comprobante (NCF) descontando stock, todo vía CounterInvoiceService. El precio se toma SIEMPRE del
 * servidor, nunca del carrito que llega del cliente.
 */
final class PartsCounterController extends Controller
{
    public function index(): View
    {
        return view('panel.parts-counter', [
            'ncfTypes' => NcfType::cases(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'tax_id']),
            'hasWarehouse' => Warehouse::query()->where('is_default', true)->exists(),
        ]);
    }

    /**
     * Búsqueda difusa de piezas para el mostrador. Responde 200 siempre (también sin resultados).
     * Las consultas ya están aisladas por empresa (CompanyScope).
     */
    public function search(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        return response()->json([
            'results' => $lookup->search((string) $request->query('q', ''), 25),
        ]);
    }

    public function invoice(IssuePartsInvoiceRequest $request, CounterInvoiceService $counter): RedirectResponse
    {
        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();
        if ($warehouse === null) {
            return back()->with('panel_error', 'No hay un almacén configurado.');
        }

        /** @var array<int, array<string, mixed>> $cart */
        $cart = json_decode((string) $request->input('cart'), true) ?: [];
        $lines = [];

        foreach ($cart as $item) {
            $product = Product::find((int) ($item['id'] ?? 0));
            if ($product === null) {
                continue;
            }
            // El precio SIEMPRE se relee del servidor; la cantidad viene del ticket.
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $lines[] = new SaleLineData($product->id, (string) $qty, (string) $product->price);
        }

        if ($lines === []) {
            return back()->with('panel_error', 'El ticket está vacío.');
        }

        try {
            $result = $counter->invoice(
                data: new CreateSaleData(
                    warehouseId: $warehouse->id,
                    lines: $lines,
                    paid: (string) $request->input('paid'),
                    customerName: $request->filled('customer_name') ? (string) $request->input('customer_name') : null,
                    customerId: $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
                ),
                type: NcfType::from((string) $request->input('type')),
                customerTaxId: $request->filled('customer_tax_id') ? (string) $request->input('customer_tax_id') : null,
            );
        } catch (InsufficientStockException) {
            return back()->with('panel_error', 'Stock insuficiente para facturar el ticket.');
        } catch (InsufficientPaymentException) {
            return back()->with('panel_error', 'El pago es menor que el total.');
        } catch (InvoiceException|FiscalSequenceException $e) {
            // RNC obligatorio/ inválido, o sin secuencia de NCF activa del tipo elegido.
            return back()->withInput()->with('panel_error', $e->getMessage());
        }

        $sale = $result['sale'];
        $invoice = $result['invoice'];

        return back()
            ->with('panel_ok', "Factura {$invoice->ncf} emitida. Venta {$sale->code}.")
            ->with('pos_receipt_id', $sale->id);
    }
}
