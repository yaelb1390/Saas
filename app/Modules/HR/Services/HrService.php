<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\DTOs\CreateEmployeeData;
use App\Modules\HR\Models\Attendance;
use App\Modules\HR\Models\Employee;
use DomainException;

/**
 * Lógica de RRHH: alta de empleados y control de asistencia (entrada/salida).
 */
final class HrService
{
    public function hire(CreateEmployeeData $data): Employee
    {
        return Employee::create($data->toAttributes());
    }

    /**
     * Marca la entrada. No permite dos entradas abiertas simultáneas.
     */
    public function clockIn(Employee $employee): Attendance
    {
        $open = $employee->attendances()->whereNull('clock_out')->exists();

        if ($open) {
            throw new DomainException("El empleado {$employee->id} ya tiene una asistencia abierta.");
        }

        return $employee->attendances()->create([
            'company_id' => $employee->company_id,
            'clock_in' => now(),
        ]);
    }

    /**
     * Marca la salida de la asistencia abierta.
     */
    public function clockOut(Employee $employee): Attendance
    {
        $attendance = $employee->attendances()->whereNull('clock_out')->latest('clock_in')->first();

        if ($attendance === null) {
            throw new DomainException("El empleado {$employee->id} no tiene una asistencia abierta.");
        }

        $attendance->update(['clock_out' => now()]);

        return $attendance;
    }
}
