<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\DTOs\SelectedOptionData;

/**
 * Convierte los ids de opción que envía el navegador en opciones verificadas y con precio de
 * confianza.
 *
 * Es una frontera de seguridad, no una comodidad. Sin comprobar la pertenencia, un cliente
 * manipulado podría enviar el id de una opción de OTRO producto —por ejemplo una con recargo
 * negativo— y rebajarse el precio a voluntad. Aquí solo se aceptan opciones activas de los grupos
 * que ese producto ofrece, y el recargo se lee siempre de la base.
 */
final class OptionResolver
{
    /**
     * @param  array<int, mixed>  $optionIds
     * @return array<int, SelectedOptionData>
     */
    public function resolve(Product $product, array $optionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $optionIds,
        ))));

        if ($ids === []) {
            return [];
        }

        // Los grupos que ESTE producto ofrece. Cualquier id de fuera se descarta en silencio: el
        // ticket se cobra por lo que el producto realmente admite.
        $allowedGroupIds = $product->optionGroups()->pluck('option_groups.id')->all();

        if ($allowedGroupIds === []) {
            return [];
        }

        return Option::query()
            ->whereKey($ids)
            ->whereIn('option_group_id', $allowedGroupIds)
            ->where('is_active', true)
            ->with('group')
            ->get()
            ->map(fn (Option $option): SelectedOptionData => new SelectedOptionData(
                optionId: $option->id,
                groupName: (string) $option->group?->name,
                optionName: (string) $option->name,
                priceDelta: (string) $option->price_delta,
            ))
            ->all();
    }

    /**
     * Precio unitario final: el del catálogo más los recargos de las opciones elegidas.
     *
     * Nunca baja de cero: una combinación de recargos negativos mal configurada no puede convertir
     * una venta en una devolución.
     *
     * @param  array<int, SelectedOptionData>  $options
     */
    public function unitPrice(Product $product, array $options): string
    {
        $price = (string) $product->price;

        foreach ($options as $option) {
            $price = bcadd($price, $option->priceDelta, 2);
        }

        return bccomp($price, '0', 2) < 0 ? '0.00' : $price;
    }
}
