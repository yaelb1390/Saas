<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Modules\Purchasing\Http\Requests\StoreSupplierRequest;
use App\Modules\Purchasing\Http\Requests\UpdateSupplierRequest;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class SupplierController extends Controller
{
    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        // company_id se asigna automáticamente por el trait BelongsToCompany (tenant activo).
        Supplier::create(array_merge($request->validated(), ['is_active' => true]));

        return back()->with('panel_ok', 'Proveedor creado correctamente.');
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        // El route model binding ya aísla el proveedor por la empresa activa.
        $supplier->update($request->validated());

        return back()->with('panel_ok', 'Proveedor actualizado.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return back()->with('panel_ok', 'Proveedor eliminado.');
    }
}
