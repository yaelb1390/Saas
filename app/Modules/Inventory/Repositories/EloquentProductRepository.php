<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Core\Support\BusquedaTexto;
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
        return Product::with('stock.warehouse')->where('sku', $sku)->first();
    }

    /**
     * El CompanyScope ya restringe la consulta a la empresa activa: filtrar aquí por company_id a
     * mano sería redundante y, si algún día cambiara el scope, una segunda verdad que mantener.
     */
    public function findByBarcode(string $barcode): ?Product
    {
        // Con su existencia y su almacén: es UN artículo, así que traerlos de una vez no cuesta nada
        // y evita que quien lo pinta tenga que ir a buscarlos por su cuenta.
        return Product::with('stock.warehouse')->where('barcode', $barcode)->first();
    }

    public function search(string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        $consulta = Product::query()->where('is_active', true);

        /*
         * SIN QUE IMPORTEN LAS MAYÚSCULAS, y no es un detalle.
         *
         * Antes era un `like` a secas. En MySQL eso da igual; en PostgreSQL —que es la base de este
         * proyecto— distingue, así que quien escribía «bomba» en el mostrador no encontraba nada y
         * «Bomba» encontraba tres. Nadie lo reporta: se asume que el artículo no está en el catálogo.
         */
        return BusquedaTexto::enCualquiera(
            $consulta,
            ['name', 'sku', 'barcode', 'part_number', 'brand', 'vehicle_make', 'vehicle_model'],
            $term,
        )
            /*
             * La existencia, y con ella su almacén, de una vez.
             *
             * Sin esto cada resultado pedía su propio stock por separado: veinticuatro consultas de
             * más POR CADA TECLA que pulsa quien está buscando. `catalog()` ya lo cargaba; esto solo
             * se había quedado atrás.
             */
            ->with('stock.warehouse')
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
