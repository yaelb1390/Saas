<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Conmutador de empresa para el super administrador. Guarda la empresa activa en la sesión;
 * el middleware SetCurrentCompany la aplica en las siguientes peticiones.
 */
final class CompanySwitchController extends Controller
{
    public function switch(Request $request, Company $company): RedirectResponse
    {
        abort_unless((bool) $request->user()?->is_super_admin, 403);

        $request->session()->put('active_company_id', $company->id);

        return redirect()->route('dashboard')->with('panel_ok', "Ahora estás operando: {$company->name}");
    }
}
