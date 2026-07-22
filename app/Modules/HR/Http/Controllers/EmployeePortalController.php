<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Portal del empleado: el usuario autenticado ve su propia ficha y su historial de asistencia,
 * ya aislado por la empresa activa.
 */
final class EmployeePortalController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $employee = Employee::query()
            ->where('user_id', $user->id)
            ->with(['attendances' => fn ($query) => $query->latest('clock_in')->limit(10)])
            ->first();

        return view('portal.employee', [
            'user' => $user,
            'employee' => $employee,
        ]);
    }
}
