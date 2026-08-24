<?php

declare(strict_types=1);

namespace App\Modules\Quotes\Services;

use App\Modules\Core\Support\TaxCalculator;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\CRM\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Quotes\Enums\QuoteStatus;
use App\Modules\Quotes\Models\Quote;
use App\Modules\Quotes\Models\QuoteItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crear y mantener cotizaciones.
 *
 * LA REGLA QUE GOBIERNA TODO ESTO: una cotización es un documento del pasado. Por eso aquí se
 * COPIA lo que en el POS se relee.
 *
 * En el punto de venta el precio se lee del catálogo a propósito, porque nada que venga del
 * navegador debe decidir lo que se cobra. Aquí es al revés y por una razón distinta: lo que se
 * ofertó es un compromiso con fecha. Si el martes sube el precio del catálogo, la cotización del
 * lunes tiene que seguir diciendo lo del lunes hasta que caduque; lo contrario es prometer un precio
 * y cobrar otro, que es la peor cosa que puede hacer un sistema de ventas.
 *
 * Lo mismo con el nombre del cliente y el texto de cada línea: se copian, no se leen de la ficha ni
 * del catálogo. Si mañana renombran el producto, el papel que tiene el cliente en la mano no cambia.
 */
final class QuoteService
{
    /** Días de validez por omisión. Quince es lo habitual en el comercio y da margen a decidir. */
    public const VALIDEZ_POR_OMISION = 15;

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * Crea una cotización con sus líneas.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function crear(array $lineas, array $datos): Quote
    {
        return DB::transaction(function () use ($lineas, $datos): Quote {
            $companyId = (int) ($this->currentCompany->id() ?? 0);

            if ($companyId === 0) {
                throw new RuntimeException('No hay una empresa activa.');
            }

            $cliente = $this->cliente($datos);

            $quote = new Quote([
                'company_id' => $companyId,
                'code' => $this->siguienteCodigo($companyId),
                'customer_id' => $cliente?->id,
                // Copiados, no leídos: ver el comentario de la clase.
                'customer_name' => $this->nombre($datos, $cliente),
                'customer_phone' => $this->telefono($datos, $cliente),
                'status' => QuoteStatus::Draft,
                'valid_until' => $this->validez($datos),
                'notes' => filled($datos['notes'] ?? null) ? (string) $datos['notes'] : null,
                'discount_total' => $this->aDecimal($datos['discount_total'] ?? '0'),
                'user_id' => auth()->id(),
            ]);
            $quote->save();

            $this->reemplazarLineas($quote, $lineas);

            return $quote->refresh()->load('items');
        });
    }

    /**
     * Cambia las líneas de una cotización y vuelve a sumar.
     *
     * Se borran y se vuelven a crear en vez de casarlas una a una: una cotización tiene cinco líneas,
     * no cinco mil, y el emparejado incremental es donde se cuelan los descuadres entre lo que se
     * enseña y lo que se guarda.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     */
    public function reemplazarLineas(Quote $quote, array $lineas): Quote
    {
        return DB::transaction(function () use ($quote, $lineas): Quote {
            if (! $quote->estadoReal()->sePuedeEditar()) {
                throw new RuntimeException('Esta cotización ya no se puede editar.');
            }

            $quote->items()->delete();

            foreach ($lineas as $linea) {
                $this->crearLinea($quote, (array) $linea);
            }

            return $this->recalcular($quote);
        });
    }

    /**
     * Vuelve a sumar los totales a partir de las líneas guardadas.
     *
     * El ITBIS sale del MISMO sitio que en una venta —`TaxCalculator`— y no de una cuenta escrita
     * aquí. Es lo que garantiza que el total de la cotización y el de la venta que salga de ella
     * coincidan al céntimo; con dos fórmulas parecidas, el descuadre aparece el día del redondeo.
     */
    public function recalcular(Quote $quote): Quote
    {
        $bruto = '0';

        foreach ($quote->items()->get() as $item) {
            $bruto = bcadd($bruto, (string) $item->subtotal, 2);
        }

        // El descuento del total se resta ANTES de desglosar el impuesto: si se restara después, el
        // ITBIS se habría calculado sobre un importe que el cliente no llega a pagar.
        $bruto = bcsub($bruto, (string) $quote->discount_total, 2);

        if (bccomp($bruto, '0', 2) < 0) {
            $bruto = '0.00';
        }

        $desglose = TaxCalculator::fromConfig()->breakdown($bruto);

        $quote->forceFill([
            'subtotal' => $desglose['subtotal'],
            'tax' => $desglose['tax'],
            'total' => $desglose['total'],
        ])->save();

        return $quote->refresh();
    }

    /** Marca que se envió. La hora sirve para saber cuánto lleva el cliente sin contestar. */
    public function marcarEnviada(Quote $quote): Quote
    {
        if ($quote->status === QuoteStatus::Draft) {
            $quote->forceFill(['status' => QuoteStatus::Sent])->save();
        }

        // La hora se actualiza SIEMPRE, aunque ya estuviera enviada: reenviar es un hecho nuevo, y
        // «se le mandó hace tres días» es lo que decide si toca llamar.
        $quote->forceFill(['sent_at' => Carbon::now()])->save();

        return $quote->refresh();
    }

    public function marcarEstado(Quote $quote, QuoteStatus $estado): Quote
    {
        if ($quote->status === QuoteStatus::Converted) {
            throw new RuntimeException('Esta cotización ya se convirtió en venta.');
        }

        $quote->forceFill([
            'status' => $estado,
            'accepted_at' => $estado === QuoteStatus::Accepted ? Carbon::now() : $quote->accepted_at,
        ])->save();

        return $quote->refresh();
    }

    /**
     * @param  array<string, mixed>  $linea
     */
    private function crearLinea(Quote $quote, array $linea): void
    {
        $producto = filled($linea['product_id'] ?? null)
            ? Product::query()->find((int) $linea['product_id'])
            : null;

        $cantidad = $this->aDecimal($linea['quantity'] ?? '1', 3);
        $descuento = $this->aDecimal($linea['discount'] ?? '0');

        /*
         * El precio: el que se teclea, y si no se teclea, el del catálogo EN ESTE MOMENTO.
         *
         * Se copia ahora y no se vuelve a mirar. Ese es el sentido de cotizar: cuando el cliente
         * vuelva con el papel, el papel manda.
         */
        $precio = filled($linea['unit_price'] ?? null)
            ? $this->aDecimal($linea['unit_price'])
            : $this->aDecimal((string) ($producto?->price ?? '0'));

        $descripcion = filled($linea['description'] ?? null)
            ? (string) $linea['description']
            : (string) ($producto?->name ?? 'Concepto');

        QuoteItem::create([
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'product_id' => $producto?->id,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'unit_price' => $precio,
            'discount' => $descuento,
            'subtotal' => QuoteItem::importe($cantidad, $precio, $descuento),
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function cliente(array $datos): ?Customer
    {
        return filled($datos['customer_id'] ?? null)
            // Con el scope de empresa puesto: un id de otro negocio no aparece y la cotización se
            // queda sin cliente en vez de engancharse a una ficha ajena.
            ? Customer::query()->find((int) $datos['customer_id'])
            : null;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function nombre(array $datos, ?Customer $cliente): string
    {
        if (filled($datos['customer_name'] ?? null)) {
            return (string) $datos['customer_name'];
        }

        return (string) ($cliente?->name ?? 'Cliente');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function telefono(array $datos, ?Customer $cliente): ?string
    {
        $telefono = filled($datos['customer_phone'] ?? null)
            ? (string) $datos['customer_phone']
            : (string) ($cliente?->phone ?? '');

        return trim($telefono) === '' ? null : trim($telefono);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function validez(array $datos): Carbon
    {
        return filled($datos['valid_until'] ?? null)
            ? Carbon::parse((string) $datos['valid_until'])
            : Carbon::now()->addDays(self::VALIDEZ_POR_OMISION);
    }

    /**
     * Código correlativo.
     *
     * Cuenta las que hay, incluidas las archivadas: sin `withTrashed()`, borrar una haría que la
     * siguiente reutilizara su código y chocara contra el índice único. Es el mismo tropiezo que ya
     * documenta `SaleService::nextCode()`, y aquí se evita desde el principio.
     */
    private function siguienteCodigo(int $companyId): string
    {
        $cuantas = Quote::withoutCompanyScope()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->count();

        return 'COT-'.str_pad((string) ($cuantas + 1), 6, '0', STR_PAD_LEFT);
    }

    private function aDecimal(mixed $valor, int $escala = 2): string
    {
        $numero = is_numeric($valor) ? (string) $valor : '0';

        return bcadd($numero, '0', $escala);
    }
}
