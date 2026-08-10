<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories\Contracts;

use App\Modules\Inventory\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepositoryInterface
{
    public function find(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * Busca por código de barras exacto (lo que emite el lector). Único por empresa.
     */
    public function findByBarcode(string $barcode): ?Product;

    /**
     * Búsqueda difusa por texto para el mostrador: nombre, SKU, código, número de parte, marca y
     * compatibilidad de vehículo. Solo productos activos. El CompanyScope ya aísla por empresa.
     *
     * @return Collection<int, Product>
     */
    public function search(string $term, int $limit = 20): Collection;

    /**
     * Catálogo para la rejilla táctil del punto de venta: productos activos, opcionalmente de una
     * categoría, ordenados por nombre y PAGINADOS.
     *
     * La paginación no es un adorno: cargar el catálogo entero de golpe fue justo lo que se retiró
     * del POS de mostrador por rendimiento. El CompanyScope ya aísla por empresa.
     *
     * @return LengthAwarePaginator<int, Product>
     */
    public function catalog(?int $categoryId = null, int $perPage = 60): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product;

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator;
}
