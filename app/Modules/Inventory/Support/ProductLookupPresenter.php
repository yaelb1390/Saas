<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;

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

        return ['found' => true, 'product' => $this->row($product)];
    }

    /**
     * Búsqueda difusa por texto para el mostrador: devuelve la MISMA forma que un escaneo, aplanada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        return $this->products->search($term, $limit)
            ->map(fn (Product $product): array => $this->row($product))
            ->all();
    }

    /**
     * El precio viaja para poder pintar el ticket, pero NO es el que se cobra: al cobrar, el
     * servidor vuelve a leerlo de la base e ignora cualquier valor que llegue del cliente.
     *
     * «sellable» y «reason» distinguen «no se puede vender» de «no existe»: el cajero necesita
     * saber por qué, y no es lo mismo un código desconocido que un artículo agotado.
     *
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        $stock = (string) $product->totalStock();

        $reason = match (true) {
            ! $product->is_active => 'inactive',
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
            'stock' => $stock,
            'sellable' => $reason === null,
            'reason' => $reason,
        ];
    }
}
