<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Services;

use App\Modules\Cash\Enums\CashSessionStatus;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Inventory\Models\Product;
use App\Modules\POS\Services\CheckoutService;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Sales\DTOs\CreateSaleData;
use App\Modules\Sales\DTOs\SaleLineData;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte una cotización aceptada en una venta.
 *
 * Pasa por `CheckoutService`, EL MISMO motor que usa el punto de venta. No hay un segundo camino
 * para vender: si lo hubiera, tarde o temprano uno de los dos se olvidaría de descontar stock, de
 * meter el dinero en la caja o de disparar los eventos, y el descuadre aparecería semanas después
 * sin que nadie supiera de dónde salió.
 *
 * Lo único propio de aquí son dos reglas:
 *
 * 1. **Se cobra LO COTIZADO, no lo que diga hoy el catálogo.** El cliente aceptó un precio con
 *    fecha. Releerlo al convertir convertiría la cotización en un papel decorativo.
 *
 * 2. **Se convierte UNA sola vez.** Un doble clic, un reintento o dos pestañas abiertas son el caso
 *    normal, no el raro, y cobrar dos veces al mismo cliente no se arregla solo.
 */
final class QuoteConverter
{
    /**
     * El código del producto donde se cuelga lo que no es mercancía.
     *
     * Uno por empresa. No se traduce ni se configura: es una pieza de fontanería interna, y darle un
     * nombre configurable solo serviría para que dos empresas acabaran con dos productos distintos
     * haciendo lo mismo.
     */
    private const SKU_SERVICIOS = 'SERVICIOS';

    public function __construct(private readonly CheckoutService $checkout) {}

    /**
     * @param  array<string, mixed>  $cobro  payment_method, paid
     */
    public function convertir(Quote $quote, array $cobro = []): Sale
    {
        /*
         * ¿Ya se convirtió?
         *
         * Se mira ANTES que nada y se devuelve la venta que ya existe. Contestar con un error haría
         * que el vendedor lo intentara otra vez con el cliente delante; devolver la venta buena le
         * enseña exactamente lo que quería ver.
         */
        if ($quote->sale_id !== null && ($existente = Sale::query()->find($quote->sale_id)) !== null) {
            return $existente;
        }

        if (! $quote->sePuedeConvertir()) {
            throw new RuntimeException($this->porQueNo($quote));
        }

        $lineas = $this->lineas($quote);

        if ($lineas === []) {
            throw new RuntimeException('La cotización no tiene líneas que se puedan vender.');
        }

        $session = CashSession::query()
            ->where('status', CashSessionStatus::Open)
            ->latest('opened_at')
            ->first();

        /*
         * Sin caja abierta no se cobra, igual que en el mostrador.
         *
         * No es una traba burocrática: el dinero de esta venta tiene que entrar en el arqueo de
         * alguien. Registrarla sin caja dejaría un cobro que no aparece en ningún cierre.
         */
        if ($session === null) {
            throw new RuntimeException('No hay una caja abierta. Ábrela antes de cobrar la cotización.');
        }

        $warehouse = Warehouse::query()->where('is_default', true)->orderBy('id')->first();

        if ($warehouse === null) {
            throw new RuntimeException('No hay un almacén configurado.');
        }

        return DB::transaction(function () use ($quote, $lineas, $session, $warehouse, $cobro): Sale {
            $metodo = PaymentMethod::tryFrom((string) ($cobro['payment_method'] ?? '')) ?? PaymentMethod::Cash;

            $venta = $this->checkout->checkout($session, new CreateSaleData(
                warehouseId: (int) $warehouse->id,
                lines: $lineas,
                paymentMethod: $metodo,
                // Si no se dice cuánto se recibió, se toma el total: la cotización ya lo trae
                // calculado y es lo que el cliente aceptó pagar.
                paid: filled($cobro['paid'] ?? null) ? (string) $cobro['paid'] : (string) $quote->total,
                customerName: $quote->customer_name,
                customerId: $quote->customer_id,
                // El descuento del ticket viaja tal cual: forma parte del precio que se ofertó.
                discountTotal: (string) $quote->discount_total,
            ));

            $quote->forceFill([
                'status' => QuoteStatus::Converted,
                'sale_id' => $venta->id,
                'accepted_at' => $quote->accepted_at ?? Carbon::now(),
            ])->save();

            return $venta;
        });
    }

    /**
     * Las líneas, con el precio COTIZADO. TODAS, también las que no son productos.
     *
     * Esto se escribió primero de otra manera —las líneas sin producto se saltaban, con un aviso en
     * pantalla— y estaba MAL. Se vio con números reales: una cotización de RD$1.895 con RD$1.500 de
     * mano de obra registraba una venta de RD$395. El aviso estaba ahí, pero un aviso no cobra: el
     * negocio perdía mil quinientos pesos cada vez que alguien no lo leyera.
     *
     * Así que la mano de obra se cobra igual, colgada de un producto de servicios que no lleva
     * existencias. La venta suma exactamente lo que el cliente aceptó, que es la única cifra
     * defendible.
     *
     * @return array<int, SaleLineData>
     */
    private function lineas(Quote $quote): array
    {
        $lineas = [];
        $servicios = null;

        foreach ($quote->items()->get() as $item) {
            // El producto de servicios se crea solo si hace falta: una cotización sin mano de obra no
            // tiene por qué dejar nada nuevo en el catálogo.
            $productId = $item->product_id ?? ($servicios ??= $this->productoDeServicios($quote))->id;

            $lineas[] = new SaleLineData(
                productId: (int) $productId,
                quantity: (string) $item->quantity,
                // EL PRECIO DE LA COTIZACIÓN. Es el punto entero de este servicio.
                unitPrice: (string) $item->unit_price,
                discount: (string) $item->discount,
                // El texto de la línea viaja en la nota: en el recibo, «Servicios y mano de obra»
                // repetido tres veces no le dice nada al cliente, pero «Instalación» sí.
                note: $item->description,
            );
        }

        return $lineas;
    }

    /**
     * El producto al que se cuelga lo que no es mercancía.
     *
     * Uno por empresa, creado la primera vez que hace falta. Va SIN control de existencias —la mano
     * de obra no se cuenta en un almacén— y FUERA del catálogo activo, para que no aparezca en el
     * punto de venta ni en los buscadores: no es algo que se venda tocándolo en una rejilla, es el
     * asiento contable de un concepto que ya se cotizó.
     *
     * La alternativa era dejar de cobrar esas líneas, y eso ya se probó: perdía dinero.
     */
    private function productoDeServicios(Quote $quote): Product
    {
        $servicios = Product::withTrashed()->firstOrCreate(
            ['company_id' => $quote->company_id, 'sku' => self::SKU_SERVICIOS],
            [
                'name' => 'Servicios y mano de obra',
                'description' => 'Se usa para cobrar los conceptos de una cotización que no son mercancía.',
                'price' => '0',
                'cost' => '0',
                'track_stock' => false,
                'is_active' => false,
            ],
        );

        /*
         * Si alguien lo archivó, se recupera.
         *
         * Buscarlo con withTrashed() es obligatorio —si no, se crearía uno nuevo cada vez y el índice
         * de SKU acabaría chocando—, pero devolver uno archivado rompería el cobro más adelante:
         * SaleService lo busca con findOrFail(), que NO ve los archivados, y la venta moriría con un
         * «no encontrado» que no señala a ninguna parte.
         */
        if ($servicios->trashed()) {
            $servicios->restore();
        }

        return $servicios;
    }

    /**
     * Lo que hay que avisar ANTES de cobrar.
     *
     * Se enseña en la pantalla de conversión, no se corrige solo. Si el precio cambió, quien decide
     * es la persona que tiene al cliente delante: puede respetar lo ofertado o renegociar, pero
     * tiene que saberlo. Cambiarlo en silencio le cobraría al cliente un número que no aceptó.
     *
     * @return array<int, string>
     */
    public function diferencias(Quote $quote): array
    {
        $avisos = [];

        foreach ($quote->items()->with('product')->get() as $item) {
            if ($item->product_id === null) {
                // Se cobra igual (ver lineas()); lo único que no hace es mover existencias, porque no
                // hay nada que descontar de un almacén.
                $avisos[] = sprintf('«%s» se cobra como servicio: no descuenta existencias.', $item->description);

                continue;
            }

            $producto = $item->product;

            if ($producto === null) {
                $avisos[] = sprintf('«%s» ya no existe en el catálogo.', $item->description);

                continue;
            }

            if (bccomp((string) $item->unit_price, (string) $producto->price, 2) !== 0) {
                $avisos[] = sprintf(
                    'Se cotizó «%s» a %s y hoy el catálogo dice %s. Se cobrará lo cotizado.',
                    $item->description,
                    number_format((float) $item->unit_price, 2),
                    number_format((float) $producto->price, 2),
                );
            }
        }

        return $avisos;
    }

    private function porQueNo(Quote $quote): string
    {
        return match ($quote->estadoReal()) {
            QuoteStatus::Converted => 'Esta cotización ya se convirtió en venta.',
            QuoteStatus::Expired => 'Esta cotización caducó. Cambia la fecha de validez si sigue en pie.',
            QuoteStatus::Rejected => 'Esta cotización está rechazada: el cliente dijo que no.',
            default => 'Esta cotización no se puede convertir en venta.',
        };
    }
}
