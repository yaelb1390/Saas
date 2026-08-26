<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Support\Facades\Gate;

/**
 * Resuelve el código que emite un lector y lo aplana a datos serializables.
 *
 * Vive en Inventario porque el producto es suyo, y lo consumen tanto el POS (cobrar) como la
 * entrada de mercancía: así ambos rinden exactamente la misma forma y no hay dos verdades.
 *
 * La búsqueda es EXACTA, no por aproximación: un escaneo es una identidad, no una búsqueda. Se
 * intenta primero el código de barras y luego el SKU, porque muchos negocios imprimen su propio
 * SKU como código en la etiqueta.
 *
 * El aislamiento por empresa lo aplica el CompanyScope a través del repositorio: aquí no se filtra
 * por company_id a mano.
 */
final class ProductLookupPresenter
{
    public function __construct(private readonly ProductRepositoryInterface $products) {}

    /**
     * @return array{found: bool, product: array<string, mixed>|null}
     */
    public function payload(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['found' => false, 'product' => null];
        }

        $product = $this->products->findByBarcode($code) ?? $this->products->findBySku($code);

        if ($product === null) {
            return ['found' => false, 'product' => null];
        }

        return ['found' => true, 'product' => $this->row($product, conDesglose: true)];
    }

    /**
     * Búsqueda difusa por texto para el mostrador: devuelve la MISMA forma que un escaneo, aplanada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        return $this->products->search($term, $limit)
            ->map(fn (Product $product): array => $this->row($product, conDesglose: true))
            ->all();
    }

    /**
     * Catálogo para la rejilla táctil: la misma forma aplanada, pero paginada y por categoría.
     *
     * Devuelve `has_more` en vez del total: la rejilla solo necesita saber si pintar el botón de
     * «cargar más», y contar filas de más no aporta nada al cajero.
     *
     * @return array{results: array<int, array<string, mixed>>, has_more: bool}
     */
    public function catalog(?int $categoryId = null, int $perPage = 60): array
    {
        $page = $this->products->catalog($categoryId, $perPage);

        return [
            'results' => $page->getCollection()
                ->map(fn (Product $product): array => $this->row($product))
                ->all(),
            'has_more' => $page->hasMorePages(),
        ];
    }

    /**
     * El precio viaja para poder pintar el ticket, pero NO es el que se cobra: al cobrar, el
     * servidor vuelve a leerlo de la base e ignora cualquier valor que llegue del cliente.
     *
     * «sellable» y «reason» distinguen «no se puede vender» de «no existe»: el cajero necesita
     * saber por qué, y no es lo mismo un código desconocido que un artículo agotado.
     *
     * @param  bool  $conDesglose  ¿Se añade en qué almacén está la existencia?
     *                             Solo para el escaneo y la búsqueda. En el catálogo NO: esa copia
     *                             guarda dos mil artículos en el navegador para poder cobrar sin
     *                             línea, y ahí el desglose la engorda sin servir de nada, porque sin
     *                             conexión nadie está mirando en qué estante hay.
     * @return array<string, mixed>
     */
    private function row(Product $product, bool $conDesglose = false): array
    {
        $stock = (string) $product->totalStock();

        $reason = match (true) {
            ! $product->is_active => 'inactive',
            // «Hoy no hay»: se acabó el guineo. Va ANTES del stock porque es una decisión de alguien
            // y no un cálculo, y porque un producto sin control de existencia nunca daría «no_stock».
            ! $product->is_available => 'unavailable',
            $product->track_stock && bccomp($stock, '0', 3) <= 0 => 'no_stock',
            default => null,
        };

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'part_number' => $product->part_number,
            'brand' => $product->brand,
            'vehicle' => $product->vehicleFit(),
            'location' => $product->location,
            'price' => (string) $product->price,
            /*
             * El COSTO solo para quien puede dar entrada de mercancía.
             *
             * Este presenter lo comparten el punto de venta y el almacén, así que ponerlo sin
             * condición se lo enseñaría al cajero: sabría lo que le cuesta cada producto al negocio
             * con solo abrir las herramientas del navegador. Lo necesita la pantalla de entradas para
             * avisar de que el costo cambió, y nadie más.
             */
            'cost' => Gate::allows('stock.adjust') ? (string) $product->cost : null,
            'stock' => $stock,
            // La unidad de medida: «12» no significa lo mismo en unidades que en metros o en libras,
            // y en una ferretería media existencia se cuenta en algo que no son piezas.
            'unit' => $product->unit,
            'description' => $product->description,
            'stock_por_almacen' => $conDesglose ? $this->porAlmacen($product) : null,
            'image' => $product->imageUrl(),
            // La rejilla táctil filtra por categoría en el cliente sin volver al servidor.
            'category_id' => $product->category_id,
            // ¿Hay que preguntar tamaño o sabor antes de añadirlo? Solo el SÍ o el NO: las opciones
            // concretas se piden al tocar el producto, no de golpe para todo el catálogo.
            'has_options' => ($product->option_groups_count ?? 0) > 0,
            'sellable' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Dónde está la existencia, almacén por almacén.
     *
     * Un total de «12» no le sirve al dependiente si ocho están en la sucursal del otro lado de la
     * ciudad: le diría que sí a un cliente y luego no tendría qué entregarle.
     *
     * Se salta lo que está a cero: enseñar «Sucursal 2: 0» es ruido, y con muchos almacenes tapa las
     * dos líneas que de verdad importan.
     *
     * @return array<int, array{almacen: string, cantidad: string}>
     */
    private function porAlmacen(Product $product): array
    {
        // Sin la relación cargada esto sería una consulta por artículo. Quien llama con desglose la
        // trae ya puesta; si no está, se prefiere no decir nada antes que provocar un N+1 escondido.
        if (! $product->relationLoaded('stock')) {
            return [];
        }

        return $product->stock
            ->filter(static fn ($fila): bool => bccomp((string) $fila->quantity, '0', 3) > 0)
            ->map(static fn ($fila): array => [
                'almacen' => (string) ($fila->warehouse?->name ?? 'Sin almacén'),
                'cantidad' => bcadd((string) $fila->quantity, '0', 3),
            ])
            ->values()
            ->all();
    }
}
