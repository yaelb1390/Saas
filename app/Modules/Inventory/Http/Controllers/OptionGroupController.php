<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Http\Requests\StoreOptionGroupRequest;
use App\Modules\Inventory\Http\Requests\StoreOptionRequest;
use App\Modules\Inventory\Models\Option;
use App\Modules\Inventory\Models\OptionGroup;
use App\Modules\Inventory\Services\OptionGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Administración de los grupos de opciones y sus opciones.
 *
 * El aislamiento por empresa no se comprueba aquí: `OptionGroup` y `Option` llevan el ámbito global
 * de empresa, así que un id de otra empresa sencillamente no se encuentra y devuelve 404.
 */
final class OptionGroupController extends Controller
{
    public function store(StoreOptionGroupRequest $request, OptionGroupService $groups): RedirectResponse
    {
        $groups->create($request->validated());

        return back()->with('panel_ok', 'Grupo creado. Añádele sus opciones y asígnalo a los productos.');
    }

    public function update(StoreOptionGroupRequest $request, OptionGroup $optionGroup, OptionGroupService $groups): RedirectResponse
    {
        $groups->update($optionGroup, $request->validated());

        return back()->with('panel_ok', 'Grupo actualizado.');
    }

    public function destroy(OptionGroup $optionGroup, OptionGroupService $groups): RedirectResponse
    {
        $groups->delete($optionGroup);

        return back()->with('panel_ok', 'Grupo eliminado. Los productos que lo usaban dejan de ofrecerlo; las ventas ya cobradas no cambian.');
    }

    public function storeOption(StoreOptionRequest $request, OptionGroup $optionGroup, OptionGroupService $groups): RedirectResponse
    {
        $groups->addOption(
            $optionGroup,
            $request->string('name')->toString(),
            (string) $request->input('price_delta'),
        );

        return back()->with('panel_ok', 'Opción añadida.');
    }

    public function updateOption(StoreOptionRequest $request, Option $option, OptionGroupService $groups): RedirectResponse
    {
        $groups->updateOption($option, [
            'name' => $request->string('name')->toString(),
            'price_delta' => (string) $request->input('price_delta'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('panel_ok', 'Opción actualizada.');
    }

    public function destroyOption(Option $option, OptionGroupService $groups): RedirectResponse
    {
        $groups->deleteOption($option);

        return back()->with('panel_ok', 'Opción eliminada. Los tickets antiguos la siguen mostrando tal como se vendió.');
    }

    public function syncProducts(Request $request, OptionGroup $optionGroup, OptionGroupService $groups): RedirectResponse
    {
        // El formulario manda un valor vacío de relleno para poder distinguir «ningún producto» de
        // «no se envió el campo»: sin él, desmarcarlos todos no cambiaría nada. Se descarta aquí.
        $validated = $request->validate([
            'products' => ['present', 'array'],
            'products.*' => ['nullable', 'integer'],
        ]);

        $ids = array_values(array_filter(
            $validated['products'],
            static fn ($id) => $id !== null && $id !== '',
        ));

        // Los ids no se comprueban contra la empresa aquí: el servicio los busca con `Product::find`,
        // que arrastra el ámbito de empresa, así que un id ajeno devuelve null y se ignora. Enviar a
        // mano el id de un producto de otra empresa no lo engancha a nada.
        $groups->syncProducts($optionGroup, $ids);

        return back()->with('panel_ok', 'Productos actualizados para este grupo.');
    }
}
