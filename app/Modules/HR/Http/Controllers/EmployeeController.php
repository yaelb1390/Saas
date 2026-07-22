<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Http\Requests\StoreEmployeeRequest;
use App\Modules\HR\Http\Requests\UpdateEmployeeRequest;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\HrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

final class EmployeeController extends Controller
{
    public function store(StoreEmployeeRequest $request, HrService $hr): RedirectResponse
    {
        $data = $request->validated();

        $hr->hire(new CreateEmployeeData(
            name: $data['name'],
            email: $data['email'] ?? null,
            position: $data['position'] ?? null,
            salary: isset($data['salary']) ? (string) $data['salary'] : null,
        ));

        return back()->with('panel_ok', 'Empleado contratado correctamente.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $data = $request->validated();
        $data['salary'] = isset($data['salary']) ? (string) $data['salary'] : null;

        $employee->update($data);

        return back()->with('panel_ok', 'Empleado actualizado.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return back()->with('panel_ok', 'Empleado eliminado.');
    }
}
