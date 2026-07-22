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
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Product;

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator;
}
