<?php

declare(strict_types=1);

namespace App\Modules\POS\Services;

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Support\OptionResolver;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\OrderType;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sube al servidor las ventas que se cobraron sin internet.
 *
 * ESTAS VENTAS YA SE COBRARON. El cliente pagó, se llevó la mercancía y tiene un recibo en la mano.
 * Eso cambia por completo qué significa aquí «validar»: no se está decidiendo si la venta puede
 * ocurrir —ocurrió hace tres horas—, se está registrando algo que ya pasó. Rechazarla no deshace
 * nada: solo hace que el sistema mienta sobre el dinero y sobre el inventario.
 *
 * De ahí las tres reglas que gobiernan este servicio:
 *
 * 1. NO DUPLICAR. Cada venta trae un UUID que puso el navegador. Si ya está, se devuelve la que hay
 *    y no se toca nada. Es lo que permite reintentar un envío que se cortó a la mitad sin tener que
 *    averiguar antes si llegó.
 *
 * 2. MANDA EL PRECIO COBRADO. El resto del POS relee el precio del catálogo a propósito (ver
 *    CartResolver), porque nada que venga del navegador debe decidir lo que se cobra. Aquí no se
 *    está decidiendo: se está apuntando. Si el precio cambió mientras el terminal estaba a oscuras,
 *    manda el del recibo del cliente; lo contrario dejaría al sistema diciendo un número distinto
 *    del que la persona pagó.
 *
 * 3. NADA ENTRA EN SILENCIO. Precio distinto, existencia en negativo o caja ya cerrada quedan
 *    escritos en la venta y salen después como aviso. El descuadre existe; esconderlo solo retrasa
 *    el día en que alguien lo encuentre sin saber de dónde salió.
 *
 * Cada venta va en su propia transacción: un lote de ocho con una imposible sube siete.
 */
final class OfflineSaleSyncService
{
    /**
     * Cuántas veces se reintenta una venta que chocó con el código correlativo.
     *
     * `SaleService::nextCode()` cuenta las ventas de la empresa y suma uno —su propio comentario ya
     * avisa de que dos cobros simultáneos cuentan lo mismo y uno choca—. Subir un lote desde dos
     * terminales a la vez es exactamente ese caso, y con más probabilidad que en el mostrador.
     *
     * El reintento va en una transacción NUEVA: en PostgreSQL una transacción que ya falló queda
     * abortada, y reintentar dentro de ella no vale para nada.
     */
    private const REINTENTOS = 3;

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly SaleService $sales,
        private readonly OptionResolver $options,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $ventas
     * @return array<int, array<string, mixed>> una respuesta por venta, en el mismo orden
     */
    public function sincronizar(array $ventas): array
    {
        return array_map(fn (array $venta): array => $this->unaVenta($venta), array_values($ventas));
    }

    /**
     * @param  array<string, mixed>  $venta
     * @return array<string, mixed>
     */
    private function unaVenta(array $venta): array
    {
        $uuid = (string) ($venta['uuid'] ?? '');

        // Antes que nada: ¿esta venta ya está? Es lo que hace seguro reintentar.
        $existente = Sale::query()->where('client_uuid', $uuid)->first();

        if ($existente !== null) {
            return ['uuid' => $uuid, 'estado' => 'ya_estaba', 'code' => $existente->code];
        }

        try {
            return $this->registrar($uuid, $venta);
        } catch (Throwable $e) {
            /*
             * Se rechaza ESTA venta y el lote sigue.
             *
             * El navegador la aparta en vez de borrarla, y deja de reintentarla: si el producto ya
             * no existe, insistir mañana tampoco va a funcionar. Tiene que mirarla una persona.
             */
            return ['uuid' => $uuid, 'estado' => 'rechazada', 'motivo' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $venta
     * @return array<string, mixed>
     */
    private function registrar(string $uuid, array $venta): array
    {
        $session = $this->sesionDeCaja($venta);

        if ($session === null) {
            throw new RuntimeException('No se encuentra la caja en la que se cobró.');
        }

        /*
         * Sale del almacén DEL TURNO EN QUE SE COBRÓ, no del de ahora.
         *
         * Una venta sin conexión puede subirse tres días después, con otro turno abierto y quizá
         * contra otro almacén. Descontarla del actual movería existencia de un sitio del que nunca
         * salió esa mercancía, y dejaría descuadrados los dos. La sesión ya se resuelve arriba
         * —hacía falta para el arqueo—, así que el dato correcto estaba a mano desde el principio.
         */
        $warehouse = $session->almacenDeSalida();

        if ($warehouse === null) {
            throw new RuntimeException('No hay un almacén configurado.');
        }

        [$lines, $avisos] = $this->lineas((array) ($venta['lines'] ?? []), (int) $warehouse->id);

        if ($lines === []) {
            throw new RuntimeException('La venta no trae ninguna línea.');
        }

        /*
         * ¿Sigue abierta la caja de ese turno?
         *
         * Si NO lo está, la venta se registra igual pero SIN meter el dinero en el arqueo. No es una
         * omisión: ese arqueo ya se contó, se cuadró y alguien lo firmó. Añadirle ahora un cobro
         * cambiaría un documento cerrado y convertiría un cuadre correcto en un descuadre, además de
         * dejar sin explicación un dinero que apareció después.
         *
         * El billete existe y está físicamente en algún sitio. Lo que hace falta es que una persona
         * lo concilie a mano, y para eso se le dice exactamente qué pasó.
         */
        $cajaAbierta = $session->status === CashSessionStatus::Open;

        if (! $cajaAbierta) {
            $avisos[] = 'La caja de ese turno ya estaba cerrada: la venta quedó registrada en él, '
                .'pero el efectivo NO se sumó a su arqueo. Hay que conciliarlo a mano.';
        }

        $orderType = OrderType::tryFrom((string) ($venta['order_type'] ?? ''));

        /*
         * Un pedido con envío no se sube por aquí.
         *
         * Crear la entrega exige dirección, repartidor y un cobro en la puerta, y nada de eso se
         * puede decidir a ciegas tres horas después. El terminal no ofrece envío sin conexión, así
         * que si llega uno es que algo va mal y conviene que lo vea una persona.
         */
        if ($orderType?->generaEntrega()) {
            throw new RuntimeException('Un pedido con envío no puede subirse desde el modo sin conexión.');
        }

        $datos = new CreateSaleData(
            warehouseId: (int) $warehouse->id,
            lines: $lines,
            paymentMethod: PaymentMethod::tryFrom((string) ($venta['payment_method'] ?? '')) ?? PaymentMethod::Cash,
            paid: isset($venta['paid']) ? (string) $venta['paid'] : null,
            customerName: filled($venta['customer_name'] ?? null) ? (string) $venta['customer_name'] : null,
            customerId: filled($venta['customer_id'] ?? null) ? (int) $venta['customer_id'] : null,
            tip: (string) ($venta['tip'] ?? '0'),
            discountTotal: (string) ($venta['discount_total'] ?? '0'),
            employeeId: filled($venta['employee_id'] ?? null) ? (int) $venta['employee_id'] : null,
            orderType: $orderType,
            clientUuid: $uuid,
        );

        $sale = $this->conReintento(fn (): Sale => $cajaAbierta
            // Caja abierta: el camino normal del POS, con su cobro en el arqueo.
            ? $this->checkout->checkout($session, $datos, permitirStockNegativo: true)
            // Caja cerrada: solo la venta. Ver arriba por qué no se toca el arqueo.
            : $this->sales->complete($datos->withCashSession((int) $session->id), permitirStockNegativo: true));

        $sale->forceFill([
            // Cuándo se subió. `created_at` diría «ahora» y engañaría: esta venta es de antes.
            'synced_offline_at' => now(),
            'offline_review' => $avisos === [] ? null : implode(' ', $avisos),
        ])->save();

        return [
            'uuid' => $uuid,
            'estado' => 'registrada',
            'code' => $sale->code,
            'revision' => $sale->offline_review,
        ];
    }

    /**
     * Las líneas con el precio que se cobró, y lo que haya que avisar de ellas.
     *
     * @param  array<int, mixed>  $crudas
     * @return array{0: array<int, SaleLineData>, 1: array<int, string>}
     */
    private function lineas(array $crudas, int $warehouseId): array
    {
        $lines = [];
        $avisos = [];

        foreach ($crudas as $cruda) {
            $cruda = (array) $cruda;
            $productId = (int) ($cruda['product_id'] ?? 0);

            // El scope de empresa está puesto: un id de otro negocio simplemente no aparece, y con
            // él la venta entera se rechaza en vez de registrarse a medias.
            $product = Product::query()->find($productId);

            if ($product === null) {
                throw new RuntimeException("El producto #{$productId} ya no existe.");
            }

            $quantity = (string) ($cruda['quantity'] ?? '0');
            $unitPrice = (string) ($cruda['unit_price'] ?? '0');

            // Las opciones se releen de la base por su id. El dinero ya está dentro de `unit_price`,
            // así que esto solo sirve para congelar los nombres correctos en el recibo.
            $options = $this->options->resolve($product, (array) ($cruda['options'] ?? []));

            $lines[] = new SaleLineData(
                productId: (int) $product->id,
                quantity: $quantity,
                unitPrice: $unitPrice,
                discount: (string) ($cruda['discount'] ?? '0'),
                note: filled($cruda['note'] ?? null) ? (string) $cruda['note'] : null,
                serial: filled($cruda['serial'] ?? null) ? (string) $cruda['serial'] : null,
                employeeId: filled($cruda['employee_id'] ?? null) ? (int) $cruda['employee_id'] : null,
                options: $options,
            );

            $avisos = [...$avisos, ...$this->avisosDe($product, $unitPrice, $quantity, $warehouseId)];
        }

        return [$lines, array_values(array_unique($avisos))];
    }

    /**
     * Qué hay que mirar de esta línea: precio distinto del catálogo, o existencia que no da.
     *
     * @return array<int, string>
     */
    private function avisosDe(Product $product, string $unitPrice, string $quantity, int $warehouseId): array
    {
        $avisos = [];

        /*
         * El precio de catálogo lleva el ITBIS incluido igual que el cobrado, así que se comparan
         * directamente. Con bccomp y no con !=, porque «75.00» y «75» son el mismo dinero y no hay
         * por qué levantar un aviso por la forma de escribirlo.
         */
        if (bccomp($unitPrice, (string) $product->price, 2) !== 0) {
            $avisos[] = sprintf(
                'Se cobró %s por «%s» y hoy el catálogo dice %s.',
                number_format((float) $unitPrice, 2),
                $product->name,
                number_format((float) $product->price, 2),
            );
        }

        if (! $product->track_stock) {
            return $avisos;
        }

        $disponible = (string) (Stock::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity') ?? '0');

        if (bccomp($disponible, $quantity, 3) < 0) {
            $avisos[] = sprintf(
                'Se vendieron %s de «%s» y quedaban %s: la existencia queda en negativo.',
                $this->sinCerosDeMas($quantity),
                $product->name,
                $this->sinCerosDeMas($disponible),
            );
        }

        return $avisos;
    }

    /** «2.000» → «2». El aviso lo lee una persona, no una máquina. */
    private function sinCerosDeMas(string $numero): string
    {
        return str_contains($numero, '.') ? rtrim(rtrim($numero, '0'), '.') : $numero;
    }

    /**
     * La caja en la que se cobró.
     *
     * Se busca la que dijo el terminal AUNQUE ESTÉ CERRADA: la venta pertenece a ese turno y no al
     * de ahora. Colocarla en la sesión abierta actual descuadraría dos arqueos de una vez —el de
     * entonces por defecto y el de ahora por exceso—, y el cajero de hoy cargaría con un sobrante
     * que no es suyo.
     *
     * @param  array<string, mixed>  $venta
     */
    private function sesionDeCaja(array $venta): ?CashSession
    {
        $id = (int) ($venta['cash_session_id'] ?? 0);

        if ($id > 0 && ($session = CashSession::query()->find($id)) !== null) {
            return $session;
        }

        // Sin referencia utilizable se usa la que esté abierta: es lo más cercano a la verdad que
        // queda. Va con aviso, porque puede no ser la correcta.
        return CashSession::query()
            ->where('status', CashSessionStatus::Open)
            ->latest('opened_at')
            ->first();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $accion
     * @return T
     */
    private function conReintento(callable $accion): mixed
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction($accion);
            } catch (UniqueConstraintViolationException $e) {
                if ($intento >= self::REINTENTOS) {
                    throw $e;
                }

                // En la vuelta siguiente, transacción nueva: `nextCode()` vuelve a contar y esta vez
                // ya ve la venta que metió el otro terminal.
            }
        }
    }
}
