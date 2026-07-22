<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Controllers;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Exceptions\CashSessionException;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Acciones del Punto de Venta. Delgado: valida la entrada y delega en los servicios de dominio
 * (Cash + POS Checkout). El precio se toma siempre del servidor, nunca del cliente.
 */
final class PosController extends Controller
{
    public function openSession(Request $request, CashService $cash): RedirectResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $register = CashRegister::query()->where('is_active', true)->orderBy('id')->first()
            ?? CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01', 'is_active' => true]);

        try {
            $cash->open($register, (string) $data['opening_amount'], auth()->id());
        } catch (CashSessionException $e) {
            return back()->with('pos_error', $e->getMessage());
        }

        return back()->with('pos_ok', 'Caja abierta correctamente.');
    }

    /**
     * Resuelve el código que llega del lector (o tecleado) y devuelve el producto para el ticket.
     *
     * Responde 200 siempre, también cuando el código no existe: así el terminal puede distinguir
     * «este código no está en el catálogo» de «la sesión caducó / no tienes permiso / el servidor
     * falló». En caja esos dos casos no pueden verse igual.
     *
     * Las consultas ya están aisladas por empresa (CompanyScope).
     */
    public function lookup(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        return response()->json($lookup->payload((string) $request->query('codigo', '')));
    }

    public function checkout(Request $request, CheckoutService $checkout, InvoiceService $invoices): RedirectResponse
    {
        $companyId = app(CurrentCompany::class)->id();

        $request->validate([
            'cart' => ['required', 'string'],
            'paid' => ['required', 'numeric', 'min:0'],
            'tip' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            // Las reglas «exists» consultan la base directamente, sin pasar por el CompanyScope: hay
            // que acotarlas a la empresa activa a mano, o aceptarían un id ajeno.
            'customer_id' => [
                'nullable', 'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
        ]);

        // Empleados válidos de la empresa (para validar el "atiende" por línea sin más consultas).
        $validEmployees = Employee::query()->pluck('id')->all();

        $session = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();
        if ($session === null) {
            return back()->with('pos_error', 'No hay una caja abierta.');
        }

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();
        if ($warehouse === null) {
            return back()->with('pos_error', 'No hay un almacén configurado.');
        }

        /** @var array<int, array<string, mixed>> $cart */
        $cart = json_decode((string) $request->input('cart'), true) ?: [];
        $lines = [];

        foreach ($cart as $item) {
            $product = Product::find((int) ($item['id'] ?? 0));
            if ($product === null) {
                continue;
            }

            // El precio SIEMPRE se relee del producto; cantidad/descuento/nota/serie/empleado llegan
            // del cliente y se sanean: cantidad > 0, descuento ≥ 0, empleado de la propia empresa.
            $qty = (string) max(0.001, (float) ($item['qty'] ?? 1));
            $discount = (string) max(0, (float) ($item['discount'] ?? 0));
            $employeeId = (int) ($item['employee_id'] ?? 0);
            $employeeId = in_array($employeeId, $validEmployees, true) ? $employeeId : null;

            $lines[] = new SaleLineData(
                productId: $product->id,
                quantity: $qty,
                unitPrice: (string) $product->price,
                discount: $discount,
                note: filled($item['note'] ?? null) ? (string) $item['note'] : null,
                serial: filled($item['serial'] ?? null) ? (string) $item['serial'] : null,
                employeeId: $employeeId,
            );
        }

        if ($lines === []) {
            return back()->with('pos_error', 'El ticket está vacío.');
        }

        try {
            $sale = $checkout->checkout($session, new CreateSaleData(
                warehouseId: $warehouse->id,
                lines: $lines,
                paid: (string) $request->input('paid'),
                customerName: $request->filled('customer_name') ? (string) $request->input('customer_name') : null,
                customerId: $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
                tip: (string) max(0, (float) $request->input('tip', 0)),
                discountTotal: (string) max(0, (float) $request->input('discount_total', 0)),
                employeeId: $request->filled('employee_id') ? (int) $request->input('employee_id') : null,
            ));
        } catch (InsufficientStockException) {
            return back()->with('pos_error', 'Stock insuficiente para completar la venta.');
        } catch (InsufficientPaymentException) {
            return back()->with('pos_error', 'El pago es menor que el total de la venta.');
        }

        $message = "Venta {$sale->code} cobrada. Cambio: ".number_format((float) $sale->change, 2);

        if ($request->boolean('invoice')) {
            try {
                $invoice = $invoices->issueForSale($sale, NcfType::Consumo);
                $message .= " · Factura {$invoice->ncf}";
            } catch (Throwable) {
                $message .= ' · (No se emitió NCF: sin secuencia fiscal activa)';
            }
        }

        return back()->with('pos_ok', $message)->with('pos_receipt_id', $sale->id);
    }

    public function closeSession(Request $request, CashService $cash): RedirectResponse
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $session = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();
        if ($session === null) {
            return back()->with('pos_error', 'No hay una caja abierta.');
        }

        $cash->close($session, (string) $data['counted_amount']);

        return back()->with('pos_ok', 'Caja cerrada. Diferencia: '.number_format((float) $session->refresh()->difference, 2));
    }
}
