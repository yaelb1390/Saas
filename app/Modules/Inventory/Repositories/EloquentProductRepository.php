<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\Contracts\ProductRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class EloquentProductRepository implements ProductRepositoryInterface
{
    public function find(int $id): ?Product
    {
        return Product::find($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return Product::where('sku', $sku)->first();
    }

    /**
     * El CompanyScope ya restringe la consulta a la empresa activa: filtrar aquí por company_id a
     * mano sería redundante y, si algún día cambiara el scope, una segunda verdad que mantener.
     */
    public function findByBarcode(string $barcode): ?Product
    {
        return Product::where('barcode', $barcode)->first();
    }

    public function search(string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return Product::query()
            ->where('is_active', true)
            ->where(function ($query) use ($like): void {
                foreach (['name', 'sku', 'barcode', 'part_number', 'brand', 'vehicle_make', 'vehicle_model'] as $column) {
                    $query->orWhere($column, 'like', $like);
                }
            })
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function catalog(?int $categoryId = null, int $perPage = 60): LengthAwarePaginator
    {
        return Product::query()
            ->where('is_active', true)
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            // El stock viaja en el payload de la rejilla; sin esto sería un N+1 por cada ficha.
            ->with('stock')
            // Igual con las opciones: la ficha solo necesita saber SI tiene, no cuáles.
            ->withCount('optionGroups')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $attributes): Product
    {
        return Product::create($attributes);
    }

    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Product::orderBy('name')->paginate($perPage);
    }
}
