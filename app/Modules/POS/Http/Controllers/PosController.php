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
use App\Modules\Core\Support\DbTable;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Support\ProductLookupPresenter;
use App\Modules\POS\DTOs\DeliveryOrderData;
use App\Modules\POS\Exceptions\ProductUnavailableException;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\POS\Support\CartResolver;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\Enums\OrderType;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InsufficientPaymentException;
use App\Modules\Sales\Models\Sale;
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
    public function openSession(Request $request, CashService $cash, CurrentCompany $current): RedirectResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],

            /*
             * El almacén del que va a salir la mercancía de este turno.
             *
             * La regla acota a la empresa activa A MANO: `exists` consulta la tabla directamente, sin
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

        $register = CashRegister::query()->where('is_active', true)->orderBy('id')->first()
            ?? CashRegister::create(['name' => 'Caja Principal', 'code' => 'CAJA-01', 'is_active' => true]);

        try {
            $session = $cash->open($register, (string) $data['opening_amount'], auth()->id());
        } catch (CashSessionException $e) {
            return back()->with('pos_error', $e->getMessage());
        }

        $this->fijarAlmacen($session, isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null, $register);

        return back()->with('pos_ok', 'Caja abierta correctamente.');
    }

    /**
     * Cambia el almacén del turno sin cerrar la caja.
     *
     * Hace falta porque el almacén se elige al abrir y una jornada dura ocho horas: quien se equivoca
     * a las nueve de la mañana no debería tener que cerrar el turno —y cuadrar el efectivo— para
     * corregirlo. Lo ya cobrado no se toca: esas ventas salieron del almacén que estaba puesto
     * entonces, y reescribirlas sería falsear el histórico.
     */
    public function changeWarehouse(Request $request, CurrentCompany $current): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')
                    ->where('company_id', $current->id())
                    ->where('is_active', true),
            ],
        ]);

        if (! DbTable::tieneColumna('cash_sessions', 'warehouse_id')) {
            return back()->with('pos_error', 'Elegir almacén todavía no está disponible en este servidor.');
        }

        $session = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();

        if ($session === null) {
            return back()->with('pos_error', 'No hay una caja abierta.');
        }

        $session->forceFill(['warehouse_id' => (int) $data['warehouse_id']])->save();

        return back()->with('pos_ok', 'A partir de ahora se descuenta del almacén elegido.');
    }

    /**
     * Deja apuntado en la sesión de dónde sale la mercancía.
     *
     * Si no se eligió ninguno se propone el de la sucursal de esa caja, y si la caja no tiene
     * sucursal —hoy ninguna la tiene— el de por omisión. Así el turno queda con un almacén explícito
     * aunque el cajero no toque nada, que es lo que permite dejar de adivinarlo al cobrar.
     */
    private function fijarAlmacen(CashSession $session, ?int $elegido, CashRegister $register): void
    {
        // La migración se aplica a mano y el despliegue no la corre: entre que sale el código y
        // alguien migra, esta columna no existe y escribirla tumbaría la apertura de caja.
        if (! DbTable::tieneColumna('cash_sessions', 'warehouse_id')) {
            return;
        }

        $almacen = $elegido
            ?? Warehouse::query()->where('branch_id', $register->branch_id)->where('is_active', true)->value('id')
            ?? Warehouse::query()->where('is_default', true)->orderBy('id')->value('id');

        if ($almacen !== null) {
            $session->forceFill(['warehouse_id' => (int) $almacen])->save();
        }
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

    /**
     * Búsqueda de productos para el mostrador: el cajero escribe SKU o nombre y recibe una lista
     * corta de coincidencias, en vez de traer TODO el catálogo al abrir la caja.
     *
     * Es lo que permite que el POS escale a miles de productos: la pantalla ya no carga el catálogo
     * completo (cientos de tarjetas y su stock), solo lo que se busca. Devuelve la misma forma que
     * el escaneo, así el terminal pinta ambos igual. Aislado por empresa vía CompanyScope.
     */
    public function search(Request $request, ProductLookupPresenter $lookup): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        /*
         * DESDE LA PRIMERA LETRA.
         *
         * Antes hacían falta dos, y en un mostrador eso se nota: el dependiente teclea la inicial,
         * no aparece nada, y no sabe si es que el artículo no está o que el sistema aún no ha
         * buscado. La primera letra ya es una intención.
         *
         * Lo que hacía falta para poder bajarlo no era esta línea, era el tope: la respuesta se corta
         * en 24 filas, así que una «a» no trae medio catálogo. Y el campo espera 250 ms desde la
         * última pulsación, de modo que teclear una clave larga sigue siendo UNA consulta, no ocho.
         *
         * El vacío sigue sin buscar: eso no es una búsqueda corta, es no haber empezado.
         */
        if (mb_strlen($term) < 1) {
            return response()->json(['results' => []]);
        }

        return response()->json(['results' => $lookup->search($term, 24)]);
    }

    /**
     * El catálogo entero, para que el mostrador pueda cobrar sin conexión.
     *
     * El mostrador normalmente NO carga el catálogo: busca contra el servidor a medida que el cajero
     * escribe o escanea, que es lo que le permite ir rápido con miles de productos. Pero sin línea no
     * hay a quién preguntar, así que se descarga una copia al abrir la pantalla y se guarda en el
     * equipo. Se pide una vez por turno, no una vez por venta.
     *
     * Existe aparte de la del terminal táctil porque aquella exige el módulo «quick_pos», y una
     * ferretería que solo contrató el mostrador recibiría un 403 justo cuando más falta le hace.
     */
    public function catalogo(ProductLookupPresenter $lookup): JsonResponse
    {
        // 2.000 cubre de sobra el catálogo de un colmado o una ferretería de barrio. Es un tope y no
        // una paginación a propósito: media copia del catálogo sería peor que ninguna, porque el
        // cajero no sabría qué mitad tiene.
        return response()->json($lookup->catalog(null, 2000));
    }

    public function checkout(Request $request, CheckoutService $checkout, InvoiceService $invoices, CartResolver $cart): RedirectResponse|JsonResponse
    {
        $companyId = app(CurrentCompany::class)->id();

        $request->validate([
            'cart' => ['required', 'string'],
            // Deja de ser obligatorio a secas: un pedido que paga el cliente en la puerta no recibe
            // nada en el mostrador, y exigir un importe ahí obligaría al cajero a escribir un cero
            // que no significa nada. Se sigue exigiendo para el resto, unas líneas más abajo, cuando
            // ya se sabe qué clase de pedido es.
            'paid' => ['nullable', 'numeric', 'min:0'],
            /*
             * La llave que identifica ESTE cobro, puesta por el navegador antes de mandarlo.
             *
             * Viaja también cuando hay conexión, y esa es la gracia: si la petición se corta a
             * mitad, el terminal no sabe si la venta llegó, y hasta ahora lo único que podía hacer
             * era avisar al cajero de que lo comprobara a mano. Con la llave puesta de antemano
             * puede encolarla sin miedo: o el servidor la registró ya, o la registrará al subirla,
             * pero nunca las dos veces.
             */
            'client_uuid' => ['nullable', 'uuid'],
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
            // Solo las formas de cobro al contado: cheque y crédito no cubren el total en el acto.
            // El crédito entra por otra puerta —«paga al recibir» en un pedido con envío—, donde es
            // el propio servidor quien lo decide y no algo que pueda llegar del navegador.
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)->only(PaymentMethod::counterOptions())],

            // Cómo se lleva el cliente el pedido. Solo el envío exige dirección: sin ella no hay a
            // dónde ir, y una entrega sin destino es un pedido perdido.
            'order_type' => ['nullable', Rule::enum(OrderType::class)],
            'delivery_address' => ['nullable', 'required_if:order_type,delivery', 'string', 'max:255'],
            'delivery_phone' => ['nullable', 'string', 'max:40'],
            'delivery_notes' => ['nullable', 'string', 'max:255'],
            'delivery_pay_on_arrival' => ['sometimes', 'boolean'],
            // Vacío = que lo decida el sistema. Ver DeliveryOrderData.
            'delivery_employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
        ], [
            'delivery_address.required_if' => 'Un pedido con envío necesita la dirección.',
        ]);

        /*
         * ¿Esta venta ya entró?
         *
         * Pasa cuando la respuesta se perdió pero la petición sí llegó, y el terminal reintenta. Sin
         * esta comprobación el cliente aparecería cobrado dos veces y el stock descontado dos veces,
         * y nadie se enteraría hasta cuadrar la caja.
         */
        if ($request->filled('client_uuid')) {
            $yaEstaba = Sale::query()->where('client_uuid', $request->string('client_uuid')->toString())->first();

            if ($yaEstaba !== null) {
                return $this->yaCobrada($request, $yaEstaba);
            }
        }

        $orderType = OrderType::tryFrom((string) $request->input('order_type'));

        // El envío exige el módulo contratado. Se corta aquí y no en la vista: ocultar un botón nunca
        // ha impedido que alguien mande la petición a mano.
        if ($orderType?->generaEntrega() && ! app(CurrentCompany::class)->model()?->hasModule('delivery')) {
            return $this->fallo($request, 'Tu plan no incluye el módulo de entregas.');
        }

        $session = CashSession::query()->where('status', CashSessionStatus::Open)->latest('opened_at')->first();
        if ($session === null) {
            return $this->fallo($request, 'No hay una caja abierta.');
        }

        /*
         * DE DÓNDE SALE LA MERCANCÍA: del almacén de este turno, no del de por omisión.
         *
         * Antes estaba escrito a fuego, y con dos almacenes eso significaba que lo recibido en el
         * segundo no se podía vender: el cobro lo buscaba en el principal, no lo encontraba y la
         * venta se caía por existencia insuficiente. La pantalla de entradas sí pregunta a qué
         * almacén entra, así que el sistema dejaba meter algo donde luego no se podía sacar.
         */
        $warehouse = $session->almacenDeSalida();
        if ($warehouse === null) {
            return $this->fallo($request, 'No hay un almacén configurado.');
        }

        // El precio SIEMPRE se relee del catálogo dentro del resolvedor: nada de lo que llegue del
        // navegador decide lo que se cobra. Y ahí mismo se rechaza lo que el negocio marcó como
        // agotado, que es antes de tocar la caja: no llega a abrirse ninguna transacción.
        try {
            $lines = $cart->toLines($cart->decode($request->string('cart')->toString()));
        } catch (ProductUnavailableException $e) {
            return $this->fallo($request, $e->getMessage());
        }

        if ($lines === []) {
            return $this->fallo($request, 'El ticket está vacío.');
        }

        // Lo paga el motorista en la puerta: la venta se registra a crédito y con pago cero. No es una
        // forma de pago que pueda elegir el navegador; se deduce de que el pedido lleva envío y de que
        // el cajero marcó «paga al recibir».
        $cobraElMotorista = $orderType?->generaEntrega() && $request->boolean('delivery_pay_on_arrival');

        // Fuera de ese caso, el importe recibido sigue siendo obligatorio: es lo que decide el cambio
        // que se le devuelve al cliente y lo que entra al cajón.
        if (! $cobraElMotorista && ! $request->filled('paid')) {
            return $this->fallo($request, 'Indica cuánto recibiste.');
        }

        try {
            $sale = $checkout->checkout(
                $session,
                new CreateSaleData(
                    warehouseId: $warehouse->id,
                    lines: $lines,
                    paymentMethod: $cobraElMotorista
                        ? PaymentMethod::Credit
                        : (PaymentMethod::tryFrom((string) $request->input('payment_method')) ?? PaymentMethod::Cash),
                    paid: $cobraElMotorista ? '0' : (string) $request->input('paid'),
                    customerName: $request->filled('customer_name') ? (string) $request->input('customer_name') : null,
                    customerId: $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
                    tip: (string) max(0, (float) $request->input('tip', 0)),
                    discountTotal: (string) max(0, (float) $request->input('discount_total', 0)),
                    employeeId: $request->filled('employee_id') ? (int) $request->input('employee_id') : null,
                    orderType: $orderType,
                    clientUuid: $request->filled('client_uuid')
                        ? $request->string('client_uuid')->toString()
                        : null,
                ),
                $orderType?->generaEntrega()
                    ? new DeliveryOrderData(
                        address: (string) $request->input('delivery_address'),
                        customerName: $request->filled('customer_name') ? (string) $request->input('customer_name') : null,
                        phone: $request->filled('delivery_phone') ? (string) $request->input('delivery_phone') : null,
                        notes: $request->filled('delivery_notes') ? (string) $request->input('delivery_notes') : null,
                        employeeId: $request->filled('delivery_employee_id') ? (int) $request->input('delivery_employee_id') : null,
                        cobraElMotorista: $cobraElMotorista,
                    )
                    : null,
            );
        } catch (InsufficientStockException) {
            return $this->fallo($request, 'Stock insuficiente para completar la venta.');
        } catch (InsufficientPaymentException) {
            return $this->fallo($request, 'El pago es menor que el total de la venta.');
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

        // La venta rápida cobra por fetch y espera JSON: así no recarga la página, que es lo que
        // sacaba al terminal del modo pantalla completa en cada cobro. El POS de mostrador envía un
        // formulario normal y sigue recibiendo su redirección de siempre.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'code' => $sale->code,
                'change' => (string) $sale->change,
                'receipt_id' => $sale->id,
                'receipt_url' => route('panel.sales.receipt', $sale).'?print=1',
            ]);
        }

        return back()->with('pos_ok', $message)->with('pos_receipt_id', $sale->id);
    }

    /**
     * Una venta que ya se había registrado con esta misma llave.
     *
     * Se contesta como un éxito y no como un error, porque para el cajero LO ES: la venta está
     * cobrada. Devolver un fallo le haría repetirla y ahí sí habría dos.
     */
    private function yaCobrada(Request $request, Sale $sale): RedirectResponse|JsonResponse
    {
        $message = "Venta {$sale->code} ya estaba registrada.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'code' => $sale->code,
                'change' => (string) $sale->change,
                'receipt_id' => $sale->id,
                'receipt_url' => route('panel.sales.receipt', $sale).'?print=1',
            ]);
        }

        return back()->with('pos_ok', $message)->with('pos_receipt_id', $sale->id);
    }

    /**
     * Rechazo del cobro, en el formato que espera quien llama.
     *
     * Se devuelve 422 y no 500: quedarse sin stock o sin caja abierta son reglas de negocio, no
     * averías, y el terminal debe poder mostrárselas al cajero tal cual.
     */
    private function fallo(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('pos_error', $message);
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
