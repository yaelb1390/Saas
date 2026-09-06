<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Enums\NcfType;
use App\Modules\Billing\Exceptions\FiscalSequenceException;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Http\Requests\IssuePartsInvoiceRequest;
use App\Modules\Billing\Models\FiscalSequence;
use App\Modules\Billing\Services\CounterInvoiceService;
use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Exceptions\CashSessionException;
use App\Modules\Cash\Models\CashRegister;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashService;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Support\CustomerLookupPresenter;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use App\Modules\Sales\Support\MasVendidos;
use App\Modules\Sales\Support\TopeDeDescuento;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Mostrador de repuestos: pantalla de facturación directa. Busca piezas, arma el ticket y emite el
 * comprobante (NCF) descontando stock, todo vía CounterInvoiceService. El precio se toma SIEMPRE del
 * servidor, nunca del carrito que llega del cliente.
 */
final class PartsCounterController extends Controller
{
    public function index(ProductLookupPresenter $lookup, MasVendidos $masVendidos, TopeDeDescuento $topes): View
    {
        $session = $this->turnoAbierto();

        return view('panel.parts-counter', [
            'ncfTypes' => NcfType::cases(),
            /*
             * LOS CLIENTES YA NO VIAJAN CON LA PANTALLA.
             *
             * Se cargaban TODOS los activos en un desplegable, en cada visita. Con doscientos ya
             * pesa; con dos mil, la pantalla tarda en abrir y el desplegable no sirve —nadie
             * encuentra a nadie desplazando—. Ahora se buscan bajo demanda contra
             * `panel.parts.customers`, igual que ya se hacía con el catálogo de piezas.
             *
             * El filtro de activos no se pierde: vive en `CustomerLookupPresenter`, que es donde
             * ahora se decide a quién se puede facturar.
             */
            'hasWarehouse' => Warehouse::query()->where('is_default', true)->exists(),
            // Para poder decir de dónde sale la pieza. Con uno solo, la pantalla ni lo pregunta.
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            /*
             * EL TURNO. La pantalla necesita SABERLO, no solo usarlo.
             *
             * Antes se resolvía en el servicio y aquí no se pintaba: quien facturaba no tenía forma
             * de saber si su cobro entraba en el arqueo o se quedaba fuera. Ahora la cabecera lo dice
             * y, sin turno, no se factura.
             */
            'openSession' => $session,
            'paymentMethods' => PaymentMethod::counterOptions(),
            /*
             * El PRÓXIMO NCF de cada tipo, para avisar antes y no después.
             *
             * Sin esto, que no hubiera secuencia activa —o que estuviera agotada— solo se descubría
             * al pulsar Facturar, con el ticket entero armado y el cliente delante. Es un aviso, no
             * una reserva: el número se reserva al emitir, y si otro terminal se adelanta será el
             * siguiente. Por eso se rotula como «próximo», no como «el suyo».
             */
            'siguienteNcf' => $this->siguienteNcfPorTipo(),
            /*
             * LOS PRODUCTOS RÁPIDOS salen de lo que de verdad se vende, no de una lista configurada.
             *
             * Una lista a mano se rellena el primer día y nadie vuelve a tocarla; las ventas se
             * adaptan solas a cada negocio. Van con la MISMA forma que un resultado de búsqueda para
             * que un botón rápido y una coincidencia entren al ticket por el mismo camino.
             */
            'rapidos' => $lookup->porIds($masVendidos->ids(8)),
            /*
             * El tope de rebaja de QUIEN está cobrando, para poder avisarle antes de que lo intente.
             *
             * `null` significa sin límite —tiene `sales.discount`—, que no es lo mismo que un tope
             * enorme: la pantalla tiene que poder decir «rebajas libre» en vez de un número.
             */
            'topeDescuento' => $topes->sinLimite(auth()->user()) ? null : (float) $topes->porcentaje(),
            // La tasa viaja para poder desglosar en pantalla con la MISMA regla que usa el servidor.
            'itbis' => [
                'tasa' => (float) config('billing.itbis_rate', '18'),
                'incluido' => (bool) config('billing.prices_include_tax', true),
            ],
        ]);
    }

    /**
     * Busca clientes para el mostrador: por nombre, RNC, cédula o teléfono.
     *
     * Sustituye al desplegable que cargaba TODOS los clientes activos de la empresa en cada visita.
     * Responde 200 siempre, también sin resultados, por el mismo motivo que la búsqueda de piezas:
     * quien atiende tiene que poder distinguir «este cliente no está» de «el servidor falló».
     */
    public function customers(Request $request, CustomerLookupPresenter $lookup): JsonResponse
    {
        return response()->json([
            'results' => $lookup->search((string) $request->query('q', ''), 15),
        ]);
    }

    /**
     * Abre el turno desde el mostrador.
     *
     * Existe porque facturar ahora lo exige y la apertura del punto de venta vive tras `module:pos`:
     * una empresa que solo contrató Facturación se habría quedado sin forma de abrir caja. No
     * duplica la lógica —la abre `CashService`, igual que allí—, solo la puerta.
     */
    public function openSession(Request $request, CashService $cash, CurrentCompany $current): RedirectResponse
    {
        $datos = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
            /*
             * El almacén se acota a la empresa A MANO: `exists` consulta la tabla directamente, sin
             * pasar por el CompanyScope, así que sin esto aceptaría el id de un almacén ajeno y una
             * empresa acabaría descontando existencia de otra.
             */
            'warehouse_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')
                    ->where('company_id', $current->id())
                    ->where('is_active', true),
            ],
        ]);

        $caja = CashRegister::query()->where('is_active', true)->orderBy('id')->first()
            ?? CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01', 'is_active' => true]);

        try {
            $sesion = $cash->open($caja, (string) $datos['opening_amount'], auth()->id());
        } catch (CashSessionException $e) {
            return back()->with('panel_error', $e->getMessage());
        }

        // La columna se aplica a mano en producción: si todavía no está, el turno se abre igual y la
        // mercancía sale del almacén por omisión, que es lo que pasaba antes de que existiera.
        if (isset($datos['warehouse_id']) && DbTable::tieneColumna('cash_sessions', 'warehouse_id')) {
            $sesion->forceFill(['warehouse_id' => (int) $datos['warehouse_id']])->save();
        }

        return back()->with('panel_ok', 'Caja abierta. Ya puedes facturar.');
    }

    /**
     * La forma de pago elegida, acotada a las que tienen sentido en un cobro al contado.
     *
     * `counterOptions()` deja fuera cheque y crédito a propósito: el crédito lo decide el servidor
     * en otros flujos —un pedido que se paga al recibirlo— y aceptarlo aquí desde el navegador
     * dejaría facturar sin cobrar. Ante cualquier valor inesperado se cae en efectivo.
     */
    private function formaDePago(Request $request): PaymentMethod
    {
        $elegida = PaymentMethod::tryFrom((string) $request->input('payment_method', ''));

        return $elegida !== null && in_array($elegida, PaymentMethod::counterOptions(), true)
            ? $elegida
            : PaymentMethod::Cash;
    }

    /** El turno abierto, o null. Misma regla que usa `CounterInvoiceService` al cobrar. */
    private function turnoAbierto(): ?CashSession
    {
        return CashSession::query()
            ->where('status', CashSessionStatus::Open)
            ->latest('opened_at')
            ->with('cashRegister', 'user')
            ->first();
    }

    /**
     * Qué NCF saldría hoy por cada tipo, o null si ese tipo no puede emitir.
     *
     * @return array<string, string|null>
     */
    private function siguienteNcfPorTipo(): array
    {
        $secuencias = FiscalSequence::query()->where('is_active', true)->get();
        $mapa = [];

        foreach (NcfType::cases() as $tipo) {
            $secuencia = $secuencias->firstWhere('type', $tipo->value);

            $mapa[$tipo->value] = $secuencia !== null
                && $secuencia->hasAvailableNumbers()
                && ! $secuencia->isExpired()
                    ? $secuencia->formatNcf((int) $secuencia->next_number)
                    : null;
        }

        return $mapa;
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

    public function invoice(IssuePartsInvoiceRequest $request, CounterInvoiceService $counter, TopeDeDescuento $topes): RedirectResponse
    {
        /*
         * EL ALMACÉN QUE SE ELIGIÓ, y el de por omisión solo como red.
         *
         * Estaba escrito a fuego, igual que lo estaba en el cobro del punto de venta y en el alta de
         * producto: una pieza recibida en la sucursal no se podía facturar desde aquí, porque la
         * buscaba en el principal y no la encontraba.
         */
        $elegido = $request->integer('warehouse_id') ?: null;

        $warehouse = ($elegido !== null ? Warehouse::find($elegido) : null)
            ?? Warehouse::query()->where('is_default', true)->orderBy('id')->first();

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

            /*
             * LA CANTIDAD ADMITE DECIMALES, y antes no.
             *
             * Estaba forzada con `(int)`, así que medio kilo de algo se facturaba como cero —y con el
             * `max(1, ...)`, como uno—. La columna de la base es decimal(15,3) y el DTO la lleva como
             * cadena: era la pantalla la que redondeaba, no el dominio. El mínimo es un milésimo, el
             * mismo que usa `CartResolver` en el punto de venta.
             *
             * El PRECIO se sigue releyendo del servidor y nunca se toma del navegador. Rebajar se
             * hace con el descuento, que queda registrado como tal y es trazable en los informes.
             */
            $qty = max(0.001, (float) ($item['qty'] ?? 1));
            $descuento = max(0, (float) ($item['discount'] ?? 0));

            $lines[] = new SaleLineData(
                productId: $product->id,
                quantity: number_format($qty, 3, '.', ''),
                unitPrice: (string) $product->price,
                discount: number_format($descuento, 2, '.', ''),
            );
        }

        if ($lines === []) {
            return back()->with('panel_error', 'El ticket está vacío.');
        }

        /*
         * EL TOPE DE DESCUENTO, comprobado AQUÍ y no solo en pantalla.
         *
         * La pantalla avisa para no hacer perder el tiempo, pero quien manda es esto: una petición a
         * mano con el descuento inflado tiene que encontrarse el mismo muro que el navegador. Es la
         * misma doctrina que el precio, que siempre se relee del catálogo.
         *
         * Se mide contra el BRUTO —lo que costaría sin rebajas— y no contra el neto: sobre lo ya
         * rebajado, el porcentaje se calcularía sobre un número que el propio descuento encoge, y el
         * tope daría de sí cuanto más se rebaja.
         */
        $bruto = '0';
        $descuento = '0';

        foreach ($lines as $line) {
            $bruto = bcadd($bruto, bcmul($line->quantity, $line->unitPrice, 2), 2);
            $descuento = bcadd($descuento, $line->discount, 2);
        }

        if ($topes->excedido($request->user(), $bruto, $descuento)) {
            $maximo = $topes->maximoEnDinero($request->user(), $bruto);

            return back()->withInput()->with(
                'panel_error',
                "Tu usuario puede rebajar hasta un {$topes->porcentaje()}% de la venta, es decir ".money($maximo).'. Pídeselo a quien lleve el negocio.',
            );
        }

        try {
            $result = $counter->invoice(
                data: new CreateSaleData(
                    warehouseId: $warehouse->id,
                    lines: $lines,
                    /*
                     * La forma de pago decide si el cobro engorda el arqueo del turno: solo el
                     * efectivo entra al cajón. Se acota a las del mostrador —efectivo, tarjeta y
                     * transferencia— y ante cualquier otra cosa se cae en efectivo, que es lo que
                     * hacía la pantalla cuando ni siquiera se preguntaba.
                     */
                    paymentMethod: $this->formaDePago($request),
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
        } catch (CashSessionException) {
            return back()->with('panel_error', 'No hay una caja abierta. Abre el turno para poder facturar.');
        } catch (InvoiceException|FiscalSequenceException $e) {
            // RNC obligatorio/ inválido, o sin secuencia de NCF activa del tipo elegido.
            return back()->withInput()->with('panel_error', $e->getMessage());
        } catch (Throwable $e) {
            /*
             * NUNCA UN «SQLSTATE» EN PANTALLA.
             *
             * Lo de arriba son fallos que quien factura puede resolver, y se le dicen tal cual. Esto
             * es todo lo demás —la base caída, una columna que falta, lo que sea—: al cliente se le
             * da algo accionable y el motivo técnico va al registro, que es donde sirve de algo.
             */
            report($e);

            return back()->withInput()->with('panel_error', 'No fue posible completar la venta. Vuelve a intentarlo.');
        }

        $sale = $result['sale'];
        $invoice = $result['invoice'];

        return back()
            ->with('panel_ok', "Factura {$invoice->ncf} emitida. Venta {$sale->code}.")
            ->with('pos_receipt_id', $sale->id);
    }
}
