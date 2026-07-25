<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Warehouse;
use App\Modules\Core\Tenancy\CurrentCompany;
use App\Modules\Reports\Services\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Panel principal. Demuestra el contexto de tenant resuelto por el middleware: los conteos y el
 * resumen ejecutivo ya vienen aislados por la empresa activa.
 */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentCompany $currentCompany, ReportService $reports): View
    {
        $user = $request->user();

        // Instancia compartida de la petición: el layout y las tarjetas de módulos la reutilizan.
        $company = $currentCompany->model();

        // Los datos de los gráficos se calculan solo si la empresa tiene el módulo (mismo criterio
        // que las tarjetas del dashboard): evita consultas inútiles y mantiene la visibilidad alineada.
        $isSuper = (bool) $user->is_super_admin;
        $hasModule = fn (string $key): bool => $isSuper || $company === null || $company->hasModule($key);

        $showLoans = $hasModule('loans');
        $showSales = $hasModule('sales');

        return view('dashboard', [
            'user' => $user,
            'company' => $company,
            'roles' => $user->getRoleNames(),
            'branchesCount' => Branch::count(),      // aislado por el tenant activo
            'warehousesCount' => Warehouse::count(), // aislado por el tenant activo
            'summary' => $reports->executiveSummary(),
            'collectionsTrend' => $showLoans ? $reports->collectionsTrend() : [],
            'salesTrend' => $showSales ? $reports->salesTrend() : [],
            'portfolio' => $showLoans ? $reports->loanPortfolio() : null,
        ]);
    }
}
