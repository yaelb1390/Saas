<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\SelectionType;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición de grupos de opciones («Tamaño», «Sabor», «Extras») y de sus opciones.
 *
 * Un grupo se define una vez y se reutiliza en todos los productos que lo necesiten: por eso la
 * asignación va por producto, no copiando el grupo. Cambiar el recargo de «2 bolas» se refleja en
 * todos los helados a la vez, que es justo lo que se espera.
 *
 * Las ventas ya cobradas NO se ven afectadas: cada línea guarda copiados el nombre y el recargo del
 * momento (ver SaleItemOption). Renombrar o borrar aquí no reescribe el pasado.
 */
final class OptionGroupService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): OptionGroup
    {
        return OptionGroup::create($this->normalize($data) + [
            'sort_order' => (int) (OptionGroup::query()->max('sort_order') ?? 0) + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(OptionGroup $group, array $data): OptionGroup
    {
        $group->update($this->normalize($data));

        return $group->refresh();
    }

    /**
     * Borra el grupo y lo desengancha de los productos que lo usaban.
     *
     * El borrado es lógico (el modelo usa SoftDeletes), pero el desenganche es real: si se dejara
     * el vínculo, un producto seguiría pidiendo un grupo que ya no se administra desde ninguna
     * pantalla, y nadie entendería de dónde sale.
     */
    public function delete(OptionGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $group->products()->detach();
            $group->options()->delete();
            $group->delete();
        });
    }

    public function addOption(OptionGroup $group, string $name, string $priceDelta): Option
    {
        return $group->options()->create([
            'company_id' => $group->company_id,
            'name' => $name,
            'price_delta' => $priceDelta,
            'sort_order' => (int) ($group->options()->max('sort_order') ?? 0) + 1,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(Option $option, array $data): Option
    {
        $option->update($data);

        return $option->refresh();
    }

    public function deleteOption(Option $option): void
    {
        $option->delete();
    }

    /**
     * Productos que ofrecen este grupo.
     *
     * @param  array<int, int>  $productIds
     */
    public function syncProducts(OptionGroup $group, array $productIds): void
    {
        DB::transaction(function () use ($group, $productIds): void {
            // Se recorre producto a producto en vez de tocar el pivote a pelo porque cada fila
            // necesita su company_id y su orden: Product::syncOptionGroups ya sabe ponerlos, y una
            // sola implementación evita que las dos se separen con el tiempo.
            $antes = $group->products()->pluck('products.id')->all();
            $ahora = array_map('intval', $productIds);

            foreach (array_diff($ahora, $antes) as $id) {
                $product = Product::find($id);
                $product?->syncOptionGroups([...$product->optionGroups()->pluck('option_groups.id')->all(), $group->id]);
            }

            foreach (array_diff($antes, $ahora) as $id) {
                $product = Product::find($id);
                $product?->syncOptionGroups(array_values(array_diff(
                    $product->optionGroups()->pluck('option_groups.id')->all(),
                    [$group->id],
                )));
            }
        });
    }

    /**
     * Deja el grupo coherente antes de guardarlo.
     *
     * Los límites solo tienen sentido cuando se pueden marcar varias: en un grupo de selección
     * única guardar «mínimo 2» sería una regla imposible de cumplir que bloquearía la venta. Se
     * limpian aquí, en un único sitio, en vez de confiar en que el formulario no los mande.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $tipo = $data['selection_type'] instanceof SelectionType
            ? $data['selection_type']
            : SelectionType::from((string) $data['selection_type']);

        $multiple = $tipo === SelectionType::Multiple;

        return [
            'name' => $data['name'],
            'selection_type' => $tipo,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'min_selections' => $multiple ? max(0, (int) ($data['min_selections'] ?? 0)) : 0,
            'max_selections' => $multiple ? (($data['max_selections'] ?? null) ? (int) $data['max_selections'] : null) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
